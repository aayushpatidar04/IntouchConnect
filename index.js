import 'dotenv/config';

import express from 'express';
import session from 'express-session';
import path from 'path';
import fs from 'fs';
import axios from 'axios';
import multer from 'multer';
import qrcodeImage from 'qrcode';
import logger from './logger.js';
import { messageQueue } from './queue.js';
import { fileURLToPath } from 'url';
import WebSocket from 'ws';

import makeWASocket, {
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    isJidBroadcast,
    isJidGroup,
    isJidNewsletter,
    downloadMediaMessage,
    makeCacheableSignalKeyStore,
    Browsers,
} from '@whiskeysockets/baileys';
import { Boom } from '@hapi/boom';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const logFile = path.join(__dirname, 'gateway-errors.log');
const logStream = fs.createWriteStream(logFile, { flags: 'a' });

function logError(type, err) {
    const msg = `[${new Date().toISOString()}] ${type}: ${err.stack || err}\n`;
    console.error(msg);
    logStream.write(msg);
}

process.on('unhandledRejection', err => logError('Unhandled Rejection', err));
process.on('uncaughtException', err => logError('Uncaught Exception', err));

// ── Express setup ─────────────────────────────────────────────────────────────
const app = express();

app.set('trust proxy', 1);
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ── Session ───────────────────────────────────────────────────────────────────
const isProduction = process.env.NODE_ENV === 'production';

app.use(session({
    secret: process.env.GATEWAY_SESSION_SECRET || 'whatsapp-crm-secret-change-me',
    resave: false,
    saveUninitialized: false,
    cookie: {
        httpOnly: true,
        maxAge: 24 * 60 * 60 * 1000,
        secure: isProduction,
        sameSite: isProduction ? 'none' : 'lax',
    },
}));

// ── Paths & credentials ───────────────────────────────────────────────────────
const publicDir = path.join(process.cwd(), 'src', 'public');
const AUTH_BASE = process.env.AUTH_DIR || path.join(process.cwd(), 'src', 'auth_info');
const uiUsername = process.env.GATEWAY_UI_USER || 'admin';
const uiPassword = process.env.GATEWAY_UI_PASSWORD || 'password';
const CRM_MAX_MEDIA_BYTES = Number(process.env.CRM_MAX_MEDIA_BYTES || 4 * 1024 * 1024);
const CRM_MEDIA_UPLOAD_URL = stripTrailingSlash(process.env.CRM_MEDIA_UPLOAD_URL || '');
const CRM_MEDIA_UPLOAD_TIMEOUT_MS = Number(process.env.CRM_MEDIA_UPLOAD_TIMEOUT_MS || 60000);

let cachedBaileysVersion = null;

// ── FIX #1: Restriction tracking (In-memory + should sync to DB) ──────────────
const restrictedNumbers = new Map(); // `${sessionId}:${phone}` => { status, expiresAt, reason, errorCode }

// ── Multi-session state ───────────────────────────────────────────────────────
const sessions = new Map();

function createSessionState(sessionId) {
    return {
        id: sessionId,
        socket: null,
        isReady: false,
        status: 'disconnected',
        qrCodeData: null,
        connectedPhone: null,
        manualResetInProgress: false,
        reconnectTimer: null,
        reconnectCount: 0,
        qrExpiredCount: 0,
        processedMessageIds: new Set(),
        processedMessageIdQueue: [],
        generation: 0,
        lockInterval: null, // Used for heartbeat lock
        msgRetryCounterCache: new Map(),
    };
}

function getOrCreateSession(sessionId) {
    if (!sessions.has(sessionId)) {
        const session = createSessionState(sessionId);

        // Load persistent data into RAM on boot
        const savedMeta = loadSessionMetadata(sessionId);
        if (savedMeta) {
            session.status = savedMeta.status === 'connected' ? 'connecting' : savedMeta.status;
            session.connectedPhone = savedMeta.phone;
        }

        sessions.set(sessionId, session);
    }
    return sessions.get(sessionId);
}

function recordProcessedMessageId(session, messageId) {
    if (!messageId || session.processedMessageIds.has(messageId)) return false;
    session.processedMessageIds.add(messageId);
    session.processedMessageIdQueue.push(messageId);
    if (session.processedMessageIdQueue.length > 500) {
        session.processedMessageIds.delete(session.processedMessageIdQueue.shift());
    }
    return true;
}

// ── Auth dir & Metadata helpers ───────────────────────────────────────────────
function getAuthDir(sessionId) {
    return path.join(AUTH_BASE, sessionId);
}

function clearAuthState(sessionId) {
    const dir = getAuthDir(sessionId);
    try {
        if (fs.existsSync(dir)) fs.rmSync(dir, { recursive: true, force: true });
        fs.mkdirSync(dir, { recursive: true });
        logger.info(`[${sessionId}] Auth cleared — fresh QR will be generated.`);
    } catch (err) {
        logger.error(`[${sessionId}] clearAuthState error:`, err.message);
    }
}

function saveSessionMetadata(session) {
    const dir = getAuthDir(session.id);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });

    const metadataFile = path.join(dir, 'metadata.json');
    const dataToSave = {
        id: session.id,
        status: session.status,
        phone: session.connectedPhone,
        lastUpdated: new Date().toISOString()
    };

    try { fs.writeFileSync(metadataFile, JSON.stringify(dataToSave, null, 2)); }
    catch (err) { logger.error(`[${session.id}] Failed to save metadata: ${err.message}`); }
}

function loadSessionMetadata(sessionId) {
    const metadataFile = path.join(getAuthDir(sessionId), 'metadata.json');
    if (!fs.existsSync(metadataFile)) return null;
    try { return JSON.parse(fs.readFileSync(metadataFile, 'utf8')); }
    catch (err) { return null; }
}

// ── Heartbeat Lock System (Fixed Race Condition) ───────────────────────────────
const LOCK_DIR = path.join(process.cwd(), 'locks');
if (!fs.existsSync(LOCK_DIR)) fs.mkdirSync(LOCK_DIR, { recursive: true });

function acquireSessionLock(sessionId) {
    const lockFile = path.join(LOCK_DIR, `${sessionId}.lock`);
    try {
        // O_EXCL is atomic — only one process can create the file
        const fd = fs.openSync(lockFile, 'wx');
        fs.writeSync(fd, `Locked by PID: ${process.pid}\n`);
        fs.fsyncSync(fd); // Ensure it's written to disk
        fs.closeSync(fd);
        return true;
    } catch (err) {
        if (err.code === 'EEXIST') return false; // Another process got it
        throw err;
    }
}

function isSessionLocked(sessionId) {
    const lockFile = path.join(LOCK_DIR, `${sessionId}.lock`);
    if (!fs.existsSync(lockFile)) return false;

    try {
        const stats = fs.statSync(lockFile);
        const ageInMs = Date.now() - stats.mtimeMs;
        if (ageInMs >= 30000) {
            // Stale lock — process probably died
            try { fs.unlinkSync(lockFile); } catch { }
            return false;
        }
        return true;
    } catch { return false; }
}

function startLockHeartbeat(session) {
    const lockFile = path.join(LOCK_DIR, `${session.id}.lock`);

    session.lockInterval = setInterval(() => {
        try {
            const now = new Date();
            fs.utimesSync(lockFile, now, now);
        } catch (err) {
            // If we can't touch the lock, we lost it — clear interval
            clearInterval(session.lockInterval);
            session.lockInterval = null;
        }
    }, 10000);
}

function clearLockHeartbeat(session) {
    if (session.lockInterval) {
        clearInterval(session.lockInterval);
        session.lockInterval = null;
    }
    const lockFile = path.join(LOCK_DIR, `${session.id}.lock`);
    try { if (fs.existsSync(lockFile)) fs.unlinkSync(lockFile); } catch { }
}

// ── Process exit cleanup ──────────────────────────────────────────────────────
function cleanupAllLocks() {
    for (const [id, session] of sessions) {
        clearLockHeartbeat(session);
    }
}

process.on('SIGINT', () => {
    cleanupAllLocks();
    process.exit(0);
});

process.on('SIGTERM', () => {
    cleanupAllLocks();
    process.exit(0);
});

// ── Auth middleware ───────────────────────────────────────────────────────────
const ensureUIAuth = (req, res, next) => {
    if (req.session?.loggedIn) return next();
    if (req.headers.accept?.includes('text/html')) return res.redirect('/login');
    return res.status(401).json({ error: 'Unauthorized' });
};

const gatewayAuth = (req, res, next) => {
    if (req.headers['x-gateway-secret'] !== process.env.GATEWAY_SECRET) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    next();
};

// ── UI Routes ─────────────────────────────────────────────────────────────────
app.get('/', (req, res) => req.session?.loggedIn ? res.redirect('/ui') : res.sendFile(path.join(publicDir, 'index.html')));
app.get('/login', (req, res) => req.session?.loggedIn ? res.redirect('/ui') : res.sendFile(path.join(publicDir, 'index.html')));

app.post('/login', (req, res) => {
    const { username, password } = req.body;
    if (username === uiUsername && password === uiPassword) {
        req.session.loggedIn = true;
        logger.info(`UI login by ${username}`);
        return res.json({ success: true });
    }
    logger.warn(`UI login failed for ${username}`);
    return res.status(401).json({ error: 'Invalid credentials' });
});

app.get('/ui', ensureUIAuth, (req, res) => res.sendFile(path.join(publicDir, 'ui.html')));
app.use(express.static(publicDir));

// ── Multer — preserve original filename + extension ───────────────────────────
const multerStorage = multer.diskStorage({
    destination: (req, file, cb) => {
        const dir = 'temp_uploads/';
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
        cb(null, dir);
    },
    filename: (req, file, cb) => {
        const safe = file.originalname.replace(/[^a-zA-Z0-9._-]/g, '_');
        cb(null, `${Date.now()}_${safe}`);
    },
});
const upload = multer({ storage: multerStorage });

// ── Helpers ───────────────────────────────────────────────────────────────────
function sanitisePhone(raw) {
    const digits = String(raw || '').replace(/\D/g, '');
    let normalized = '';
    if (digits.length === 10) normalized = `91${digits}`;
    else if (digits.length === 11 && digits.startsWith('0')) normalized = `91${digits.slice(1)}`;
    else if (digits.length === 12 && digits.startsWith('91')) normalized = digits;
    else if (digits.length === 13 && digits.startsWith('091')) normalized = digits.slice(1);
    else if (digits.length === 14 && digits.startsWith('0091')) normalized = digits.slice(2);

    if (!normalized || !/^[6-9]\d{9}$/.test(normalized.slice(2))) return '';
    return normalized;
}
function stripTrailingSlash(url) { return url ? url.replace(/\/+$/, '') : url; }

async function uploadMediaToCRM({ sessionId, fromPhone, filename, mimetype, buffer, messageId, mediaType }) {
    if (!CRM_MEDIA_UPLOAD_URL) return null;
    try {
        const headers = {
            'X-Gateway-Secret': process.env.GATEWAY_SECRET, 'X-Session-Id': sessionId, 'X-From-Phone': fromPhone,
            'X-Message-Id': messageId, 'X-Media-Type': mediaType, 'X-File-Name': filename,
            'Content-Type': mimetype, 'Content-Length': buffer.length,
        };
        const response = await axios.post(CRM_MEDIA_UPLOAD_URL, buffer, { headers, maxBodyLength: Infinity, maxContentLength: Infinity, timeout: CRM_MEDIA_UPLOAD_TIMEOUT_MS });
        return response?.data || null;
    } catch (err) {
        console.log(err);
        logger.error(`[${sessionId}] Media upload to CRM failed:`, err.message); return null;
    }
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function mimeToExt(mime) {
    const m = {
        'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp', 'application/pdf': 'pdf',
        'video/mp4': 'mp4', 'video/quicktime': 'mov', 'audio/mpeg': 'mp3', 'audio/ogg': 'ogg', 'audio/wav': 'wav',
        'application/msword': 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
        'application/vnd.ms-excel': 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
        'text/plain': 'txt', 'text/csv': 'csv', 'application/zip': 'zip',
    };
    return m[mime] || 'bin';
}

function guessMime(filePath) {
    const ext = path.extname(filePath).toLowerCase().slice(1);
    const m = {
        jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp', pdf: 'application/pdf',
        mp4: 'video/mp4', mov: 'video/quicktime', avi: 'video/x-msvideo', mp3: 'audio/mpeg', ogg: 'audio/ogg', wav: 'audio/wav',
        doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        xls: 'application/vnd.ms-excel', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        txt: 'text/plain', csv: 'text/csv', zip: 'application/zip',
    };
    return m[ext] || 'application/octet-stream';
}

function extractText(msg) {
    const m = msg.message; if (!m) return '';
    return (m.conversation || m.extendedTextMessage?.text || m.imageMessage?.caption || m.videoMessage?.caption ||
        m.documentMessage?.caption || m.buttonsResponseMessage?.selectedDisplayText || m.templateButtonReplyMessage?.selectedDisplayText || m.listResponseMessage?.title || '');
}

function getMessageType(msg) {
    const m = msg.message; if (!m) return 'text';
    if (m.imageMessage) return 'image'; if (m.documentMessage) return 'document'; if (m.videoMessage) return 'video';
    if (m.audioMessage) return 'audio'; if (m.stickerMessage) return 'sticker'; if (m.locationMessage) return 'location';
    if (m.contactMessage) return 'contact'; return 'text';
}

function isValidInbound(msg, logger = console) {
    const jid = msg?.key?.remoteJid ?? '';
    if (!msg?.key) {
        logger.info('Inbound rejected: missing message key');
        return false;
    }

    if (msg.key.fromMe === true) {
        logger.info(
            `Inbound rejected: message is fromMe, jid=${jid}, id=${msg.key.id}`
        );
        return false;
    }

    if (!jid) {
        logger.info('Inbound rejected: missing remoteJid');
        return false;
    }

    if (isJidBroadcast(jid)) {
        logger.info(`Inbound rejected: broadcast jid=${jid}`);
        return false;
    }

    if (isJidGroup(jid)) {
        logger.info(`Inbound rejected: group jid=${jid}`);
        return false;
    }

    if (
        typeof isJidNewsletter === 'function' &&
        isJidNewsletter(jid)
    ) {
        logger.info(`Inbound rejected: newsletter jid=${jid}`);
        return false;
    }
    const isPersonalChat =
        jid.endsWith('@s.whatsapp.net') ||
        jid.endsWith('@lid') ||
        jid.endsWith('@c.us');

    if (!isPersonalChat) {
        logger.info(`Inbound rejected: unsupported jid=${jid}`);
        return false;
    }

    return true;
}

// ── CRM webhook notifier ──────────────────────────────────────────────────────
async function notifyCRM(payload, retries = 3) {
    const base = stripTrailingSlash(process.env.CRM_URL);
    if (!base) { logger.error('CRM_URL not set in .env'); return; }

    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            await axios.post(`${base}/api/gateway/webhook`, payload, {
                headers: { 'X-Gateway-Secret': process.env.GATEWAY_SECRET, 'Content-Type': 'application/json' }, timeout: 10000,
            });
            return;
        } catch (err) {
            const status = err?.response?.status;
            logger.error(`CRM notify failed (attempt ${attempt}/${retries}): HTTP ${status ?? 'N/A'} — ${err?.message}`);
            if (attempt < retries && status !== 401) await sleep(2000 * attempt);
        }
    }
}

const pendingMessages = new Map();
function trackPendingMessage(waMessageId, sessionId, chatId, messageId, maxWaitMs = 60000) {
    const tracker = {
        waMessageId,
        sessionId,
        chatId,
        messageId,
        status: 'pending',
        sentAt: Date.now(),
        expiresAt: Date.now() + maxWaitMs,
    };

    pendingMessages.set(waMessageId, tracker);

    // Auto-cleanup after max wait
    setTimeout(() => {
        const stillPending = pendingMessages.get(waMessageId);
        if (stillPending && stillPending.status === 'pending') {
            logger.warn(`[${sessionId}] Message ${waMessageId} still pending after ${maxWaitMs}ms — likely not delivered`);
            pendingMessages.delete(waMessageId);

            // Notify CRM of timeout
            notifyCRM({
                event: 'message_ack',
                session_id: sessionId,
                data: {
                    message_id: messageId,
                    wa_message_id: waMessageId,
                    ack: 0, // 0 = timeout/failed
                    status: 'failed',
                    reason: 'Delivery timeout — no receipt received',
                }
            });
        }
    }, maxWaitMs);
}

function updateMessageStatus(
    waMessageId,
    ack,
    extraData = {}
) {
    const tracker = pendingMessages.get(waMessageId);

    if (!tracker) {
        logger.debug(
            `[ACK] No pending tracker found for ${waMessageId}`,
            {
                ack,
                ...extraData,
            }
        );

        return false;
    }

    const status =
        ack === 3
            ? 'read'
            : ack === 2
                ? 'delivered'
                : ack === 1
                    ? 'sent'
                    : 'failed';

    tracker.status = status;
    tracker.lastAck = ack;
    tracker.updatedAt = Date.now();

    logger.info(
        `[${tracker.sessionId}] Message ${waMessageId} ` +
        `tracker updated to ${status}`,
        {
            ack,
            localMessageId: tracker.messageId,
            ...extraData,
        }
    );

    if (
        status === 'delivered' ||
        status === 'read' ||
        status === 'failed'
    ) {
        if (tracker.timeoutHandle) {
            clearTimeout(tracker.timeoutHandle);
        }

        pendingMessages.delete(waMessageId);

        logger.debug(
            `[${tracker.sessionId}] Pending tracker removed`,
            {
                waMessageId,
                finalStatus: status,
            }
        );
    }

    return true;
}

function scheduleSessionReconnect({
    session,
    sessionId,
    previousStatus,
    statusCode,
}) {
    if (session.reconnectTimer) {
        clearTimeout(session.reconnectTimer);
        session.reconnectTimer = null;
    }

    const isLoggedOut =
        statusCode === DisconnectReason.loggedOut ||
        statusCode === 401 ||
        statusCode === 403;

    const isConnectionReplaced =
        statusCode === DisconnectReason.connectionReplaced ||
        statusCode === 440;

    const isRestartRequired =
        statusCode === DisconnectReason.restartRequired ||
        statusCode === 515;

    const isTimeoutOrLost =
        statusCode === DisconnectReason.connectionLost ||
        statusCode === DisconnectReason.timedOut ||
        statusCode === 408;

    if (isLoggedOut) {
        logger.warn(
            `[${sessionId}] Credentials invalid or logged out; generating fresh QR.`
        );

        clearAuthState(sessionId);

        session.reconnectCount = 0;
        session.qrExpiredCount = 0;

        session.reconnectTimer = setTimeout(() => {
            connectWhatsApp(sessionId).catch(error => {
                logger.error(
                    `[${sessionId}] Logged-out reconnect failed`,
                    { error: error.message }
                );
            });
        }, 3000);

        return;
    }

    if (isRestartRequired) {
        logger.info(
            `[${sessionId}] Pairing restart required; reopening with saved credentials.`
        );

        session.reconnectTimer = setTimeout(() => {
            connectWhatsApp(sessionId).catch(error => {
                logger.error(
                    `[${sessionId}] Restart reconnect failed`,
                    { error: error.message }
                );
            });
        }, 1500);

        return;
    }

    if (isConnectionReplaced) {
        logger.error(
            `[${sessionId}] Connection replaced by another gateway process/socket.`
        );

        session.reconnectTimer = setTimeout(() => {
            connectWhatsApp(sessionId).catch(error => {
                logger.error(
                    `[${sessionId}] Replacement reconnect failed`,
                    { error: error.message }
                );
            });
        }, 30000);

        return;
    }

    if (previousStatus === 'qr_ready' && isTimeoutOrLost) {
        session.qrExpiredCount += 1;

        const delay =
            session.qrExpiredCount >= 5
                ? 5 * 60 * 1000
                : 3000;

        if (session.qrExpiredCount >= 5) {
            session.qrExpiredCount = 0;
        }

        logger.info(`[${sessionId}] QR expired`, {
            reconnectInMs: delay,
        });

        session.reconnectTimer = setTimeout(() => {
            connectWhatsApp(sessionId).catch(error => {
                logger.error(
                    `[${sessionId}] QR regeneration failed`,
                    { error: error.message }
                );
            });
        }, delay);

        return;
    }
    const delay = Math.min(
        5000 * Math.pow(2, session.reconnectCount),
        60000
    );

    session.reconnectCount += 1;

    logger.info(`[${sessionId}] Reconnect scheduled`, {
        attempt: session.reconnectCount,
        delay,
    });

    session.reconnectTimer = setTimeout(() => {
        connectWhatsApp(sessionId).catch(error => {
            logger.error(
                `[${sessionId}] Reconnect attempt failed`,
                { error: error.message }
            );
        });
    }, delay);
}

// ── Baileys connection (per session) ──────────────────────────────────────────
async function connectWhatsApp(sessionId) {
    // 1. ATOMIC LOCK ACQUISITION
    if (!acquireSessionLock(sessionId)) {
        logger.warn(`[${sessionId}] ABORT: Lock already held by another worker.`);
        return null;
    }

    const session = getOrCreateSession(sessionId);

    if (session.socket) {
        logger.warn(`[${sessionId}] Closing old socket before reconnecting.`);
        try { session.socket.end?.(); } catch (e) { }
        session.socket = null;
        session.isReady = false;
        session.status = 'disconnected';
        clearLockHeartbeat(session);
    }

    if (session.reconnectTimer) {
        clearTimeout(session.reconnectTimer);
        session.reconnectTimer = null;
    }

    // 2. START HEARTBEAT (we own the lock now)
    startLockHeartbeat(session);

    logger.info(`[${sessionId}] Connecting to WhatsApp via Baileys...`);
    session.status = 'connecting';
    saveSessionMetadata(session); // Save "connecting" status to UI

    const authDir = getAuthDir(sessionId);
    if (!fs.existsSync(authDir)) fs.mkdirSync(authDir, { recursive: true });

    const { state, saveCreds } = await useMultiFileAuthState(authDir);

    if (!cachedBaileysVersion) {
        try {
            const result = await fetchLatestBaileysVersion();
            cachedBaileysVersion = result.version;
            logger.info(`Baileys protocol version: ${cachedBaileysVersion.join('.')}`);
        } catch (err) {
            logger.warn(`Version fetch failed, using modern fallback. Error: ${err.message}`);
            cachedBaileysVersion = [2, 3000, 1023498599];
        }
    }

    const msgRetryCounterCache = session.msgRetryCounterCache;

    const silentLogger = {
        level: 'silent',
        trace: () => { }, debug: () => { }, info: () => { }, warn: () => { }, error: () => { }, fatal: () => { },
        child: function () { return silentLogger; },
    };

    const sock = makeWASocket({
        version: cachedBaileysVersion,
        auth: { creds: state.creds, keys: makeCacheableSignalKeyStore(state.keys, silentLogger) },
        logger: silentLogger,
        browser: Browsers.macOS('Arihant Capitals'),
        printQRInTerminal: false,
        syncFullHistory: false,
        markOnlineOnConnect: true,
        connectTimeoutMs: 60000,
        keepAliveIntervalMs: 10000,
        retryRequestDelayMs: 2000,
        maxMsgRetryCount: 10,
        msgRetryCounterCache,
        generateHighQualityLinkPreview: false,
        getMessage: async (key) => undefined,
    });

    session.socket = sock;
    session.generation += 1;
    const myGen = session.generation;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.process(async (events) => {
        logger.info(
            `[${sessionId}] Events: ${Object.keys(events).join(', ')}`
        );
    })

    sock.ev.on('connection.update', async (update) => {

        logger.info(`[${sessionId}] CONNECTION.UPDATE`, {
            generation: myGen,
            connection: update.connection ?? null,
            hasQr: Boolean(update.qr),
            isNewLogin: update.isNewLogin ?? null,
            receivedPendingNotifications:
                update.receivedPendingNotifications ?? null,
            userId: sock.user?.id ?? null,
            currentStatus: session.status,
            currentIsReady: session.isReady,
            hasSocket: Boolean(session.socket),
            wsType: sock.ws?.constructor?.name ?? null,
            wsReadyState: sock.ws?.readyState ?? null,
        });

        if (myGen !== session.generation) return;

        const { connection, lastDisconnect, qr } = update;


        if (qr) {
            session.qrCodeData = await qrcodeImage.toDataURL(qr);
            session.status = 'qr_ready';
            session.isReady = false;
            saveSessionMetadata(session);
            logger.info(`[${sessionId}] QR code generated — waiting for scan.`);
            await notifyCRM({ event: 'qr_generated', session_id: sessionId, message: 'QR Code generated' });
        }

        if (connection === 'open') {
            updateSessionConnectionState(session, {
                isReady: true,
                status: 'connected',
                qrCodeData: null,
                reconnectCount: 0,
                qrExpiredCount: 0,
                connectedPhone:
                    sock.user?.id?.split(':')[0] ??
                    sock.user?.id ??
                    null,
                connectedAt: new Date().toISOString(),
                disconnectedAt: null,
                lastDisconnectCode: null,
                lastDisconnectReason: null,
                lastError: null,
            });

            logger.info(`[${sessionId}] Connection OPEN`, {
                generation: myGen,
                userId: sock.user?.id,
                phone: session.connectedPhone,
            });

            await notifyCRM({
                event: 'session_ready',
                session_id: sessionId,
                phone: session.connectedPhone,
            });
        }

        if (connection === 'close') {
            const previousStatus = session.status;
            const boom =
                lastDisconnect?.error instanceof Boom
                    ? lastDisconnect.error
                    : null;

            const statusCode =
                boom?.output?.statusCode ??
                lastDisconnect?.error?.output?.statusCode ??
                lastDisconnect?.error?.statusCode ??
                0;

            const reason =
                Object.keys(DisconnectReason).find(
                    key => DisconnectReason[key] === statusCode
                ) ?? `code_${statusCode}`;

            updateSessionConnectionState(session, {
                isReady: false,
                status: 'disconnected',
                socket: null,
                disconnectedAt: new Date().toISOString(),
                lastDisconnectCode: statusCode,
                lastDisconnectReason: reason,
                lastError:
                    lastDisconnect?.error?.message ??
                    String(lastDisconnect?.error ?? ''),
            });

            clearLockHeartbeat(session);

            logger.warn(`[${sessionId}] Connection CLOSED`, {
                generation: myGen,
                previousStatus,
                statusCode,
                reason,
                errorName: lastDisconnect?.error?.name,
                errorMessage: lastDisconnect?.error?.message,
                errorStack: lastDisconnect?.error?.stack,
                boomOutput: boom?.output,
                boomData: boom?.data,
            });

            await notifyCRM({
                event: 'session_disconnected',
                session_id: sessionId,
                reason,
                status_code: statusCode,
            });

            scheduleSessionReconnect({
                session,
                sessionId,
                previousStatus,
                statusCode,
            });
        }

    });

    sock.ev.on('messages.upsert', async ({ messages: msgs, type }) => {
        try {
            logger.info(
                `[${sessionId}] MESSAGES.UPSERT type=${type}: ${JSON.stringify(msgs)}`
            );

            if (myGen !== session.generation) {
                logger.warn(
                    `[${sessionId}] Ignoring messages.upsert because socket generation is stale`,
                    {
                        socketGeneration: myGen,
                        currentGeneration: session.generation,
                    }
                );

                return;
            }

            if (type !== 'notify' && type !== 'append') {
                logger.info(
                    `[${sessionId}] Ignoring messages.upsert type=${type}`
                );

                return;
            }

            const sixtySecondsAgo =
                Math.floor(Date.now() / 1000) - 60;

            for (const msg of msgs) {
                try {
                    logger.info(
                        `Processing inbound candidate: ${JSON.stringify({
                        key: msg.key,
                            messageTimestamp: msg.messageTimestamp,
                            messageType: msg.message
                                ? Object.keys(msg.message)[0]
                                : null,
                        })}`
                    );
                    if (msg.key?.fromMe === true) {
                        logger.info(
                            `[${sessionId}] Ignoring outbound message echo`
                        );
                        continue;
                    }

                    if (!isValidInbound(msg, logger)) {
                        logger.info(
                            `[${sessionId}] Message rejected by isValidInbound: ${JSON.stringify({
                                id: msg.key?.id,
                                remoteJid: msg.key?.remoteJid,
                                fromMe: msg.key?.fromMe,
                                upsertType: type,
                            })}`
                        );
                        continue;
                    }

                    if (type === 'append') {
                        const msgTime = getMessageTimestamp(
                            msg.messageTimestamp
                        );

                        if (msgTime < sixtySecondsAgo) {
                            logger.info(
                                `[${sessionId}] Old append message skipped`,
                                {
                                    id: msg.key?.id,
                                    messageTimestamp: msgTime,
                                }
                            );

                            continue;
                        }
                    }

                    const jid = msg.key.remoteJid;

                    const rawFromPhone =
                        msg.key.senderPn ||
                        msg.key.participantPn ||
                        msg.key.remoteJidAlt ||
                        msg.key.participantAlt ||
                        jid;

                    const fromPhone = sanitisePhone(
                        rawFromPhone
                    );

                    if (!fromPhone) {
                        logger.warn(
                            `[${sessionId}] Could not resolve sender phone`,
                            {
                                id: msg.key?.id,
                                remoteJid: jid,
                                remoteJidAlt:
                                    msg.key?.remoteJidAlt,
                                senderPn:
                                    msg.key?.senderPn,
                                participantPn:
                                    msg.key?.participantPn,
                            }
                        );

                        continue;
                    }

                    const messageId = msg.key.id;
                    const body = extractText(msg);
                    const msgType = getMessageType(msg);

                    const mediaTypeMap = {
                        imageMessage: 'image',
                        documentMessage: 'document',
                        videoMessage: 'video',
                        audioMessage: 'audio',
                        stickerMessage: 'sticker',
                    };

                    const mediaKey = Object.keys(
                        mediaTypeMap
                    ).find((key) => msg.message?.[key]);

                    if (!body && !mediaKey) {
                        logger.info(
                            `[${sessionId}] Empty or unsupported inbound message skipped`,
                            {
                                id: messageId,
                                messageTypes: Object.keys(
                                    msg.message || {}
                                ),
                            }
                        );

                        continue;
                    }

                    if (
                        !recordProcessedMessageId(
                            session,
                            messageId
                        )
                    ) {
                        logger.info(
                            `[${sessionId}] Duplicate inbound message skipped: ${messageId}`
                        );

                        continue;
                    }

                    logger.info(
                        `[${sessionId}] Inbound from ${fromPhone}: "${body?.substring(0, 80) || '[media]'}"`
                    );

                    /*
                     * Forwarded information can be present inside
                     * text contextInfo or media contextInfo.
                     */
                    const messageContextInfo =
                        msg.message
                            ?.extendedTextMessage
                            ?.contextInfo ||
                        (
                            mediaKey
                                ? msg.message?.[mediaKey]
                                    ?.contextInfo
                                : null
                        );

                    const payload = {
                        from: fromPhone,
                        body: body || '',
                        type: mediaKey
                            ? mediaTypeMap[mediaKey]
                            : msgType,

                        timestamp: getMessageTimestamp(
                            msg.messageTimestamp
                        ),

                        message_id: messageId,
                        remote_jid: jid,

                        remote_jid_alt:
                            msg.key.remoteJidAlt || null,

                        sender_pn:
                            msg.key.senderPn || null,

                        participant_pn:
                            msg.key.participantPn || null,

                        push_name:
                            msg.pushName || null,

                        is_forwarded: Boolean(
                            messageContextInfo
                                ?.isForwarded
                        ),
                        /*
                         * Always initialize media fields.
                         * This makes webhook debugging easier.
                         */
                        has_media: false,
                        media: null,
                    };

                    // ── Download and prepare inbound media ─────────────
                    if (mediaKey) {
                        try {
                            const mediaMessage =
                                msg.message[mediaKey];

                            const mime =
                                mediaMessage.mimetype ||
                                (
                                    mediaKey ===
                                        'stickerMessage'
                                        ? 'image/webp'
                                        : 'application/octet-stream'
                                );

                            const extension =
                                mimeToExt(mime) || 'bin';

                            const filename =
                                mediaMessage.fileName ||
                                mediaMessage.filename ||
                                `attachment_${Date.now()}.${extension}`;

                            logger.info(
                                `[${sessionId}] Downloading inbound media`,
                                {
                                    messageId,
                                    mediaKey,
                                    mediaType:
                                        mediaTypeMap[
                                        mediaKey
                                        ],
                                    mimetype: mime,
                                    filename,
                                }
                            );

                            const buffer =
                                await downloadMediaMessage(
                                    msg,
                                    'buffer',
                                    {},
                                    {
                                        logger: silentLogger,

                                        reuploadRequest:
                                            sock.updateMediaMessage.bind(
                                                sock
                                            ),
                                    }



                                );

                            if (
                                !Buffer.isBuffer(buffer) ||
                                buffer.length === 0
                            ) {
                                throw new Error(
                                    'Downloaded media buffer is empty'
                                );
                            }

                            const bufferSize =
                                buffer.length;

                            payload.has_media = true;

                            payload.media = {
                                mimetype: mime,
                                filename,
                                size_bytes: bufferSize,
                                media_type:
                                    mediaTypeMap[mediaKey],
                            };

                            logger.info(
                                `[${sessionId}] Inbound media downloaded`,
                                {
                                    messageId,
                                    filename,
                                    mimetype: mime,
                                    sizeBytes:
                                        bufferSize,
                                    inlineLimit:
                                        CRM_MAX_MEDIA_BYTES,
                                }
                            );

                            /*
                             * Large files:
                             * upload directly to CRM storage.
                             */
                            if (
                                bufferSize >
                                CRM_MAX_MEDIA_BYTES
                            ) {
                                if (
                                    !CRM_MEDIA_UPLOAD_URL
                                ) {
                                    throw new Error(
                                        'CRM media upload URL is not configured for large media'
                                    );
                                }

                                logger.info(
                                    `[${sessionId}] Uploading large inbound media to CRM`,
                                    {
                                        messageId,
                                        filename,
                                        sizeBytes:
                                            bufferSize,
                                    }
                                );

                                const uploadResult =
                                    await uploadMediaToCRM({
                                        sessionId,
                                        fromPhone,
                                        filename,
                                        mimetype: mime,
                                        buffer,
                                        messageId,

                                        mediaType:
                                            mediaTypeMap[
                                            mediaKey
                                            ],
                                    });

                                logger.info(
                                    `[${sessionId}] CRM media upload response`,
                                    {
                                        messageId,
                                        uploadResult,
                                    }
                                );

                                const uploadedMediaUrl =
                                    uploadResult
                                        ?.media_url ||
                                    uploadResult
                                        ?.url ||
                                    uploadResult
                                        ?.file_url ||
                                    null;

                                const uploadedMediaId =
                                    uploadResult
                                        ?.media_id ||
                                    uploadResult
                                        ?.file_id ||
                                    uploadResult
                                        ?.id ||
                                    null;

                                if (
                                    !uploadedMediaUrl &&
                                    !uploadedMediaId
                                ) {
                                    throw new Error(
                                        'CRM media upload completed without a media URL or file ID'
                                    );
                                }

                                payload.media.crm_media_url =
                                    uploadedMediaUrl;

                                payload.media.crm_file_id =
                                    uploadedMediaId;

                                payload.media.note =
                                    'media stored in CRM';
                            } else {
                                /*
                                 * Small files:
                                 * send inline Base64 to Laravel.
                                 */
                                payload.media.data =
                                    buffer.toString(
                                        'base64'
                                    );

                                payload.media.note =
                                    'media sent inline';
                            }

                            logger.info(
                                `[${sessionId}] Media payload prepared`,
                                {
                                    messageId,
                                    hasMedia:
                                        payload.has_media,

                                    filename:
                                        payload.media
                                            ?.filename,

                                    mimetype:
                                        payload.media
                                            ?.mimetype,

                                    sizeBytes:
                                        payload.media
                                            ?.size_bytes,

                                    hasInlineData:
                                        Boolean(
                                            payload.media
                                                ?.data
                                        ),

                                    crmMediaUrl:
                                        payload.media
                                            ?.crm_media_url ||
                                        null,

                                    crmFileId:
                                        payload.media
                                            ?.crm_file_id ||
                                        null,
                                }
                            );
                        } catch (mediaError) {
                            logger.error(
                                `[${sessionId}] Media processing failed`,
                                {
                                    message:
                                        mediaError.message,

                                    stack:
                                        mediaError.stack,

                                    messageId,
                                    mediaKey,

                                    mediaType:
                                        mediaTypeMap[
                                        mediaKey
                                        ],
                                }
                            );

                            /*
                             * Do not tell Laravel that media is available
                             * when neither Base64 nor CRM URL was created.
                             */
                            payload.has_media = false;

                            payload.media = null;

                            payload.media_error =
                                mediaError.message;
                        }
                    }

                    logger.info(
                        `[${sessionId}] Sending incoming message payload to CRM`,
                        {
                            messageId,
                            fromPhone,
                            type: payload.type,
                            hasMedia:
                                payload.has_media,

                            media: payload.media
                                ? {
                                    filename:
                                        payload.media
                                            .filename,

                                    mimetype:
                                        payload.media
                                            .mimetype,

                                    sizeBytes:
                                        payload.media
                                            .size_bytes,

                                    hasInlineData:
                                        Boolean(
                                            payload.media
                                                .data
                                        ),

                                    crmMediaUrl:
                                        payload.media
                                            .crm_media_url ||
                                        null,

                                    crmFileId:
                                        payload.media
                                            .crm_file_id ||
                                        null,
                                }
                                : null,
                        }
                    );

                    await notifyCRM({
                        event: 'incoming_message',
                        session_id: sessionId,
                        data: payload,
                    });
                    logger.info(
                        `[${sessionId}] Incoming message sent to CRM`,
                        {
                            messageId,
                            fromPhone,
                            hasMedia:
                                payload.has_media,
                        }
                    );
                } catch (messageError) {
                    logger.error(
                        `[${sessionId}] Message processing error: ${JSON.stringify({
                            name: messageError?.name,
                            message: messageError?.message,
                            stack: messageError?.stack,
                            messageId: msg?.key?.id,
                            remoteJid: msg?.key?.remoteJid,
                            fromMe: msg?.key?.fromMe,
                            upsertType: type,
                        })}`
                    );
                }
            }
        } catch (eventError) {
            logger.error(
                `[${sessionId}] messages.upsert handler failed`,
                {
                    message:
                        eventError.message,

                    stack:
                        eventError.stack,

                    type,
                }
            );
        }
    });

    sock.ev.on('message-receipt.update', async (updates) => {
        if (myGen !== session.generation) return;

        logger.info(`[${sessionId}] RECEIPT UPDATE received: ${JSON.stringify(updates)}`);

        for (const { key, receipt } of updates) {
            if (!key.fromMe) continue;

            const ack = receipt.readTimestamp ? 3 : receipt.receiptTimestamp ? 2 : 1;

            logger.info(`[${sessionId}] Receipt for ${key.id}: ack=${ack} (${ack === 3 ? 'read' : ack === 2 ? 'delivered' : 'sent'}) to ${key.remoteJid}`);

            // Update our tracker
            updateMessageStatus(key.id, ack);

            // Notify CRM with both message_id and wa_message_id
            await notifyCRM({
                event: 'message_ack',
                session_id: sessionId,
                data: {
                    message_id: key.id, // This is the wa_message_id
                    wa_message_id: key.id, // Explicit for CRM matching
                    ack,
                    to: key.remoteJid,
                    timestamp: receipt.readTimestamp || receipt.receiptTimestamp || Date.now(),
                }
            });
        }
    });

    const cacheCleanupInterval = setInterval(() => {
        if (myGen !== session.generation) {
            clearInterval(cacheCleanupInterval);
            return;
        }

        // Clear message retry counter cache if too large
        const cache = session.msgRetryCounterCache;
        if (cache && cache.size > 1000) {
            logger.info(`[${sessionId}] Clearing msgRetryCounterCache (${cache.size} entries)`);
            cache.clear();
        }

        // Clear processed message IDs if too large
        if (session.processedMessageIds.size > 500) {
            logger.info(`[${sessionId}] Clearing processedMessageIds (${session.processedMessageIds.size} entries)`);
            session.processedMessageIds.clear();
            session.processedMessageIdQueue = [];
        }
    }, 300000);

    // ADD this new listener for server-side status updates
    sock.ev.on('messages.update', async (updates) => {
        if (myGen !== session.generation) return;

        logger.info(
            `[${sessionId}] MESSAGES.UPDATE: ${JSON.stringify(updates)}`
        );

        for (const item of updates) {
            const { key, update: msgUpdate } = item;

            if (!key?.fromMe || !key.id) {
                continue;
            }

            if (msgUpdate?.status === undefined) {
                continue;
            }

            const baileysStatus = Number(msgUpdate.status);

            const statusMap = {
                0: 'error',
                1: 'pending',
                2: 'server_ack',
                3: 'delivery_ack',
                4: 'read',
                5: 'played',
            };

            const readableStatus =
                statusMap[baileysStatus] ??
                `unknown_${baileysStatus}`;

            logger.info(
                `[${sessionId}] Message ${key.id} status update: ` +
                `${readableStatus} (code=${baileysStatus})`
            );

        /*
         * Convert Baileys message status to the ACK values expected
         * by Laravel:
         *
         * Baileys 2 = accepted by WhatsApp server → ACK 1 / sent
         * Baileys 3 = delivered to recipient      → ACK 2 / delivered
         * Baileys 4 = read by recipient           → ACK 3 / read
         * Baileys 5 = played                      → ACK 3 / read
         * Baileys 0 = error                       → ACK 0 / failed
         */
            const ackMap = {
                0: 0,
                2: 1,
                3: 2,
                4: 3,
                5: 3,
            };

            const ack = ackMap[baileysStatus];

        // Status 1 is only pending, so do not notify CRM yet.
            if (ack === undefined) {
                continue;
            }

            const tracker = pendingMessages.get(key.id);

        /*
         * This updates the tracker and, for delivered/read/failed,
         * clears the 60-second failure timeout.
         */
            updateMessageStatus(key.id, ack, {
                source: 'messages.update',
                baileysStatus,
            });

            await notifyCRM({
                event: 'message_ack',
                session_id: sessionId,
                data: {
                /*
                 * Local Laravel message ID. This is the safest way
                 * to identify the database row.
                 */
                    message_id: tracker?.messageId ?? null,

                /*
                 * WhatsApp/Baileys message ID.
                 */
                    wa_message_id: key.id,

                    ack,
                    status:
                        ack === 3
                            ? 'read'
                            : ack === 2
                                ? 'delivered'
                                : ack === 1
                                    ? 'sent'
                                    : 'failed',

                    reason:
                        ack === 0
                            ? 'WhatsApp server rejected the message'
                            : null,

                    to: key.remoteJid ?? null,
                    timestamp: Date.now(),
                    source: 'messages.update',
                    baileys_status: baileysStatus,
                },
            });
        }
    });

    return sock;
}

function getMessageTimestamp(value) {
    if (!value) {
        return Math.floor(Date.now() / 1000);
    }

    if (typeof value === 'number') {
        return value;
    }

    if (typeof value === 'string') {
        return Number(value);
    }

    if (typeof value === 'object') {
        if (typeof value.toNumber === 'function') {
            return value.toNumber();
        }

        if (typeof value.low === 'number') {
            return value.low;
        }
    }

    return Number(value) ||
        Math.floor(Date.now() / 1000);
}

// ── Direct sender ─────────────────────────────────────────────────────────────
// Sends immediately — no queue, no delay. Notifies CRM on success/failure.
// ── Direct sender ─────────────────────────────────────────────────────────────
async function sendMessageDirect({ sessionId, phone, message, media_path, media_mimetype, media_filename, message_id }) {
    const session = sessions.get(sessionId);
    if (!session?.socket) throw new Error(`Session ${sessionId} not found`);

    const sock = session.socket;

    const results = await sock.onWhatsApp(phone);
    const result2 = results?.[0];

    if (!result2?.exists) throw new Error('Number is not on WhatsApp');

    const phoneJid = result2.jid;

    logger.info(
        `[${sessionId}] onWhatsApp result: ${JSON.stringify(result2)}`
    );

    let destinationJid = phoneJid;

    try {
        const mappedLid =
            await sock.signalRepository
                ?.lidMapping
                ?.getLIDForPN?.(phoneJid);

        if (mappedLid) {
            destinationJid = mappedLid.includes('@')
                ? mappedLid
                : `${mappedLid}@lid`;

            logger.info(
                `[${sessionId}] Using mapped LID: ${phoneJid} → ${destinationJid}`
            );
        } else {
            logger.info(
                `[${sessionId}] No LID mapping found for ${phoneJid}; using PN JID`
            );
        }
    } catch (error) {
        logger.warn(
            `[${sessionId}] LID lookup failed for ${phoneJid}: ${error.message}`
        );
    }

    logger.info(
        `[${sessionId}] Final send destination: ${destinationJid}`
    );
    const chatId = destinationJid;
    logger.info(chatId);

    // ── REAL Baileys connection check ──────────────────────────────────────
    // Check 1: Do we have a user identity?
    if (!sock.user?.id) {
        session.isReady = false;
        logger.info(`Session ${sessionId} not authenticated — user is null`);
        throw new Error(`Session ${sessionId} not authenticated — user is null`);
    }

    // Check 2: Do we have valid creds?
    if (!sock.authState?.creds?.me?.id) {
        session.isReady = false;
        logger.info(`Session ${sessionId} creds corrupted`);
        throw new Error(`Session ${sessionId} creds corrupted — needs reconnection`);
    }

    // Check 3: Try a lightweight operation that fails if socket is dead
    // We use generateMessageTag() which requires valid auth state
    try {
        const testTag = sock.generateMessageTag();
        if (!testTag) throw new Error('generateMessageTag returned empty');
    } catch (e) {
        session.isReady = false;

        throw new Error(`Session ${sessionId} socket dead — generateMessageTag failed: ${e.message}`);
    }

    let result;
    let sentMsg;

    try {
        if (media_path) {
            if (!fs.existsSync(media_path)) throw new Error(`Media file missing: ${media_path}`);
            const mimeType = media_mimetype || guessMime(media_path);
            const filename = media_filename || path.basename(media_path);
            const buffer = fs.readFileSync(media_path);

            logger.info(`[${sessionId}] Sending media "${filename}" (${mimeType}) → ${chatId}`);

            let content;
            if (mimeType.startsWith('image/')) content = { image: buffer, mimetype: mimeType, caption: message || '', fileName: filename };
            else if (mimeType.startsWith('video/')) content = { video: buffer, mimetype: mimeType, caption: message || '', fileName: filename };
            else if (mimeType.startsWith('audio/')) content = { audio: buffer, mimetype: mimeType, ptt: false };
            else content = { document: buffer, mimetype: mimeType, caption: message || '', fileName: filename };

            sentMsg = await sock.sendMessage(chatId, content);
            try { fs.unlinkSync(media_path); } catch { }

        } else {
            if (!message?.trim()) throw new Error('Empty text body — skipped');
            logger.info(`[${sessionId}] Sending text → ${chatId}: "${message.substring(0, 50)}..."`);

            sentMsg = await sock.sendMessage(chatId, { text: message });
        }

        // CRITICAL: Check if Baileys actually returned a message key
        if (!sentMsg?.key?.id) {
            throw new Error('sendMessage returned empty response — WhatsApp rejected the message');
        }

        logger.info(`[${sessionId}] ✓ Message accepted by WhatsApp — WA ID: ${sentMsg.key.id}`);

        const waMessageId = sentMsg.key.id;

        // Track for delivery status
        trackPendingMessage(waMessageId, sessionId, chatId, message_id, 60000);

        logger.info(`[${sessionId}] ✓ Message queued — WA ID: ${waMessageId}`);

        result = {
            success: true,
            sessionId,
            to: phone,
            timestamp: Date.now(),
            message_id: message_id ?? null,
            wa_message_id: sentMsg.key.id,
            wa_timestamp: sentMsg.messageTimestamp ?? Date.now(),
            status: 'pending',
        };

        notifyCRM({ event: 'message_sent', data: result });
        return result;

    } catch (err) {
        logger.error(`[${sessionId}] ✗ Failed to deliver to ${chatId}: ${err.message}`);

        const errorResult = {
            success: false,
            error: err.message,
            sessionId,
            to: phone,
            message_id: message_id ?? null,
            timestamp: Date.now(),
        };

        notifyCRM({ event: 'message_failed', data: errorResult });
        throw err;
    }
}
// ── API routes (called by Laravel CRM) ───────────────────────────────────────
function buildSessionStatus(session) {
    return {
        session_id: session.id,
        status: session.status,
        is_ready: session.isReady,
        qr: session.qrCodeData,
        phone: session.connectedPhone,

        generation: session.generation,
        reconnect_count: session.reconnectCount,
        qr_expired_count: session.qrExpiredCount,

        has_socket: Boolean(session.socket),
        has_user: Boolean(session.socket?.user?.id),
        socket_user_id: session.socket?.user?.id ?? null,

        ws_type:
            session.socket?.ws?.constructor?.name ??
            null,

        ws_ready_state:
            session.socket?.ws?.readyState ??
            null,

        reconnect_scheduled:
            Boolean(session.reconnectTimer),

        process_id: process.pid,

        last_state_change_at:
            session.lastStateChangeAt ?? null,

        last_disconnect_code:
            session.lastDisconnectCode ?? null,

        last_disconnect_reason:
            session.lastDisconnectReason ?? null,

        last_error:
            session.lastError ?? null,
    };
}

function updateSessionConnectionState(session, changes) {
    Object.assign(session, changes);

    session.lastStateChangeAt = new Date().toISOString();

    saveSessionMetadata(session);
}

app.get('/status', gatewayAuth, (req, res) => {
    const all = {};
    for (const [id, s] of sessions) all[id] = buildSessionStatus(s);
    res.json(all);
});

app.get('/status/:sessionId', gatewayAuth, (req, res) => {
    const session = sessions.get(req.params.sessionId);

    if (!session) {
        return res.status(404).json({
            error: 'Session not found',
            session_id: req.params.sessionId,
        });
    }

    return res.json(buildSessionStatus(session));
});

app.get('/queue/stats', gatewayAuth, async (req, res) => {
    res.json({
        waiting: await messageQueue.getWaitingCount(), active: await messageQueue.getActiveCount(),
        completed: await messageQueue.getCompletedCount(), failed: await messageQueue.getFailedCount(),
    });
});

app.get('/message-status/:waMessageId', gatewayAuth, (req, res) => {
    const tracker = pendingMessages.get(req.params.waMessageId);
    if (!tracker) {
        return res.json({ found: false, status: 'unknown' });
    }

    res.json({
        found: true,
        wa_message_id: tracker.waMessageId,
        status: tracker.status,
        sent_at: tracker.sentAt,
        elapsed_ms: Date.now() - tracker.sentAt,
    });
});

app.post('/send', gatewayAuth, async (req, res) => {
    const { sessionId, to, message, message_id } = req.body;
    if (!sessionId || !to || !message) return res.status(400).json({ error: 'sessionId, to, and message are required' });

    const session = sessions.get(sessionId);
    if (!session?.isReady) return res.status(503).json({ error: `Session ${sessionId} not connected` });

    const phone = sanitisePhone(to);
    if (phone.length < 7) return res.status(400).json({ error: `Invalid phone: ${to}` });

    try {
        const result = await sendMessageDirect({ sessionId, phone, message, message_id });
        res.json(result);
    } catch (err) {
        notifyCRM({ event: 'message_failed', data: { error: err.message, sessionId, to: phone, message_id: message_id ?? null } });
        res.status(500).json({ error: err.message });
    }
});

app.post('/send-media', gatewayAuth, upload.single('file'), async (req, res) => {
    const { sessionId, to, caption, original_filename, mime_type, message_id } = req.body;
    if (!req.file) return res.status(400).json({ error: 'No file uploaded' });
    if (!sessionId) { try { fs.unlinkSync(req.file.path); } catch { } return res.status(400).json({ error: 'sessionId is required' });
}

    const session = sessions.get(sessionId);
    if (!session?.isReady) {
        try { fs.unlinkSync(req.file.path); } catch { } return res.status(503).json({
            error: `Session ${sessionId}
not connected` });
    }

    const phone = sanitisePhone(to);
    if (phone.length < 7) {
        try { fs.unlinkSync(req.file.path); } catch { } return res.status(400).json({
            error: `Invalid phone: ${to}`
        });
    }

    try {
        const result = await sendMessageDirect({
            sessionId,
            phone,
            message: caption || '',
            media_path: req.file.path,
            media_mimetype: mime_type || req.file.mimetype || 'application/octet-stream',
            media_filename: original_filename || req.file.originalname || path.basename(req.file.path),
            message_id,
        });
        res.json(result);
    } catch (err) {
        if (req.file?.path && fs.existsSync(req.file.path)) { try { fs.unlinkSync(req.file.path); } catch { } }
        notifyCRM({ event: 'message_failed', data: { error: err.message, sessionId, to: phone, message_id: message_id ?? null } });
        res.status(500).json({ error: err.message });
    }
});

app.post('/logout', gatewayAuth, async (req, res) => {
    const { sessionId } = req.body;

    if (!sessionId) {
        return res.status(400).json({
            error: 'sessionId required',
        });
    }

    const session = sessions.get(sessionId);

    if (!session) {
        return res.status(404).json({
            error: 'Session not found',
        });
    }

    session.manualResetInProgress = true;

    if (session.reconnectTimer) {
        clearTimeout(session.reconnectTimer);
        session.reconnectTimer = null;
    }

    try {
        const oldSocket = session.socket;

        // Invalidate all callbacks from the old socket.
        session.generation += 1;

        session.socket = null;
        session.isReady = false;
        session.status = 'resetting';
        session.qrCodeData = null;
        session.connectedPhone = null;

        clearLockHeartbeat(session);

        if (oldSocket) {
            try {
                await oldSocket.logout();
            } catch (error) {
                logger.warn(`[${sessionId}] Socket logout failed`, {
                    error: error.message,
                });
            }

            try {
                oldSocket.end?.(
                    new Error('Manual session reset')
                );
            } catch { }
        }

        clearAuthState(sessionId);

        session.status = 'disconnected';
        saveSessionMetadata(session);

        session.reconnectTimer = setTimeout(async () => {
            try {
                session.manualResetInProgress = false;
                await connectWhatsApp(sessionId);
            } catch (error) {
                logger.error(`[${sessionId}] Reset reconnect failed`, {
                    error: error.message,
                });
            }
        }, 2000);

        return res.json({
            success: true,
            session_id: sessionId,
            status: 'resetting',
        });
    } catch (err) { res.status(500).json({ error: err.message }); }
});

app.post('/session/create', gatewayAuth, async (req, res) => {
    const { sessionId } = req.body;
    if (!sessionId) return res.status(400).json({ error: 'sessionId is required' });
    if (!/^[a-zA-Z0-9_-]+$/.test(sessionId)) return res.status(400).json({ error: 'sessionId must be alphanumeric with _ or - only' });

    if (sessions.has(sessionId)) return res.json({ success: true, sessionId, alreadyExists: true, ...buildSessionStatus(sessions.get(sessionId)) });

    getOrCreateSession(sessionId);
    try { await connectWhatsApp(sessionId); res.json({ success: true, sessionId }); }
    catch (err) { res.status(500).json({ error: err.message }); }
});

app.delete('/session/:sessionId', gatewayAuth, async (req, res) => {
    const { sessionId } = req.params;
    const session = sessions.get(sessionId);
    if (!session) return res.status(404).json({ error: 'Session not found' });

    if (session.reconnectTimer) clearTimeout(session.reconnectTimer);
    if (session.socket) { await session.socket.logout().catch(() => { }); session.socket = null; }
    clearLockHeartbeat(session);
    clearAuthState(sessionId);

    // Completely remove session and its metadata file
    const metaFile = path.join(getAuthDir(sessionId), 'metadata.json');
    try { if (fs.existsSync(metaFile)) fs.unlinkSync(metaFile); } catch { }
    sessions.delete(sessionId);

    res.json({ success: true });
});

// ── UI routes (browser dashboard) ────────────────────────────────────────────
app.get('/ui/status', ensureUIAuth, (req, res) => {
    const all = [];

    for (const [, session] of sessions) {
        all.push(buildSessionStatus(session));
    }

    return res.json({ sessions: all });
});

app.post('/ui/logout', ensureUIAuth, async (req, res) => {
    const { sessionId } = req.body;

    if (!sessionId) {
        return res.status(400).json({
            error: 'sessionId required',
        });
    }

    const session = sessions.get(sessionId);

    if (!session) {
        return res.status(404).json({
            error: 'Session not found',
        });
    }

    session.manualResetInProgress = true;

    if (session.reconnectTimer) {
        clearTimeout(session.reconnectTimer);
        session.reconnectTimer = null;
    }

    const oldSocket = session.socket;

    // Invalidate all callbacks from the old socket.
    session.generation += 1;

    session.socket = null;
    session.isReady = false;
    session.status = 'resetting';
    session.qrCodeData = null;
    session.connectedPhone = null;

    clearLockHeartbeat(session);

    if (oldSocket) {
        try {
            await oldSocket.logout();
        } catch (error) {
            logger.warn(`[${sessionId}] Socket logout failed`, {
                error: error.message,
            });
        }

        try {
            oldSocket.end?.(
                new Error('Manual session reset')
            );
        } catch { }
    }

    clearAuthState(sessionId);

    session.status = 'disconnected';
    saveSessionMetadata(session);

    session.reconnectTimer = setTimeout(async () => {
        try {
            session.manualResetInProgress = false;
            await connectWhatsApp(sessionId);
        } catch (error) {
            logger.error(`[${sessionId}] Reset reconnect failed`, {
                error: error.message,
            });
        }
    }, 2000);

    return res.json({
        success: true,
        session_id: sessionId,
        status: 'resetting',
    });
});

app.post('/ui/session-destroy', ensureUIAuth, (req, res) => {
    req.session.destroy(() => { res.clearCookie('connect.sid'); res.json({ success: true }); });
});

app.use((req, res) => res.status(404).json({ error: 'Not found' }));

// ── Start server ──────────────────────────────────────────────────────────────
const PORT = parseInt(process.env.PORT || '3000', 10);

setInterval(() => {
    const usage = process.memoryUsage();
    const heapUsedMB = Math.round(usage.heapUsed / 1024 / 1024);
    const heapTotalMB = Math.round(usage.heapTotal / 1024 / 1024);

    if (heapUsedMB > 400) { // Only log if above 400MB
        logger.info(`Memory: heap ${heapUsedMB}MB / ${heapTotalMB}MB | RSS ${Math.round(usage.rss / 1024 / 1024)}MB`);
    }

    // Force GC if heap is very high (requires --expose-gc flag)
    if (heapUsedMB > 800 && global.gc) {
        logger.warn('Heap usage high, forcing garbage collection');
        global.gc();
    }
}, 60000);

app.listen(PORT, '0.0.0.0', async () => {
    logger.info(`=== WhatsApp Gateway (Baileys Multi-Session) started ===`);
    logger.info(`Port        : ${PORT}`);
    logger.info(`Environment : ${process.env.NODE_ENV || 'development'}`);
    logger.info(`Cookies     : secure=${isProduction}, sameSite=${isProduction ? 'none' : 'lax'}`);
    logger.info(`Auth base   : ${AUTH_BASE}`);
    logger.info('=======================================================');

    if (!fs.existsSync(AUTH_BASE)) fs.mkdirSync(AUTH_BASE, { recursive: true });
    const savedSessions = fs.readdirSync(AUTH_BASE).filter(f => fs.statSync(path.join(AUTH_BASE, f)).isDirectory());

    if (savedSessions.length > 0) {
        logger.info(`Restoring ${savedSessions.length} saved session(s)`);
        for (let i = 0; i < savedSessions.length; i++) {
            const sessionId = savedSessions[i];

            // Stagger starts by 2 seconds each to reduce lock contention
            setTimeout(async () => {
                if (!isSessionLocked(sessionId)) {
                    await connectWhatsApp(sessionId).catch(() => { });
                } else {
                    logger.warn(`[${sessionId}] Skipped boot restore: Another worker already has the lock.`);
                }
            }, i * 2000);
        }
    }
});