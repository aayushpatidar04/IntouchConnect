<template>
    <div class="max-w-sm">
        <!-- Image preview -->
        <button
            v-if="isImage"
            type="button"
            class="block w-full overflow-hidden rounded-xl border border-surface-200 bg-surface-50 text-left group"
            @click="openPreview"
        >
            <div
                class="relative flex min-h-32 items-center justify-center bg-surface-100"
            >
                <img
                    :src="previewUrl"
                    :alt="displayName"
                    class="max-h-80 w-full object-contain"
                    loading="lazy"
                    @load="previewLoaded = true"
                    @error="previewFailed = true"
                />

                <div
                    v-if="!previewLoaded && !previewFailed"
                    class="absolute inset-0 flex items-center justify-center text-xs text-surface-400"
                >
                    Loading image…
                </div>

                <div
                    v-if="previewFailed"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-surface-100 text-surface-500"
                >
                    <span class="text-3xl">
                        🖼️
                    </span>

                    <span class="text-xs">
                        Preview unavailable
                    </span>
                </div>
            </div>

            <FileFooter />
        </button>

        <!-- Video preview -->
        <div
            v-else-if="isVideo"
            class="overflow-hidden rounded-xl border border-surface-200 bg-black"
        >
            <video
                :src="previewUrl"
                class="max-h-80 w-full"
                controls
                preload="metadata"
                playsinline
                @error="previewFailed = true"
            />

            <FileFooter class="bg-white" />
        </div>

        <!-- Audio preview -->
        <div
            v-else-if="isAudio"
            class="rounded-xl border border-surface-200 bg-white p-3"
        >
            <div class="mb-2 flex items-center gap-2">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-purple-50"
                >
                    🎵
                </div>

                <div class="min-w-0">
                    <p
                        class="truncate text-xs font-medium text-surface-800"
                    >
                        {{ displayName }}
                    </p>

                    <p
                        class="text-[10px] text-surface-400"
                    >
                        {{ formattedSize }}
                    </p>
                </div>
            </div>

            <audio
                :src="previewUrl"
                class="w-full"
                controls
                preload="metadata"
            />
        </div>

        <!-- PDF / generic document -->
        <a
            v-else
            :href="downloadUrl"
            class="group flex max-w-xs items-center gap-3 rounded-xl border border-surface-200 bg-surface-50 px-3 py-2.5 transition-colors hover:bg-surface-100"
        >
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                :class="iconBg"
            >
                <span class="text-lg">
                    {{ icon }}
                </span>
            </div>

            <div class="min-w-0">
                <p
                    class="truncate text-xs font-medium text-surface-800"
                >
                    {{ displayName }}
                </p>

                <p
                    class="text-[10px] text-surface-400"
                >
                    {{ formattedSize }}
                </p>
            </div>

            <DownloadIcon />
        </a>

        <!-- Download action under media preview -->
        <div
            v-if="isPreviewable"
            class="mt-1 flex justify-end"
        >
            <a
                :href="downloadUrl"
                class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] font-medium text-surface-500 transition-colors hover:bg-surface-100 hover:text-brand-600"
            >
                <svg
                    class="h-3.5 w-3.5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                    />
                </svg>

                Download
            </a>
        </div>
    </div>
</template>

<script setup>
import {
    computed,
    defineComponent,
    h,
    ref,
} from 'vue';

const props = defineProps({
    document: {
        type: Object,
        required: true,
    },
});

const previewLoaded = ref(false);
const previewFailed = ref(false);

const mimeType = computed(() =>
    String(
        props.document.mime_type || ''
    ).toLowerCase()
);

const mediaCategory = computed(() => {
    if (props.document.media_category) {
        return props.document.media_category;
    }

    if (
        mimeType.value.startsWith('image/')
    ) {
        return 'image';
    }

    if (
        mimeType.value.startsWith('video/')
    ) {
        return 'video';
    }

    if (
        mimeType.value.startsWith('audio/')
    ) {
        return 'audio';
    }

    if (
        mimeType.value ===
        'application/pdf'
    ) {
        return 'pdf';
    }

    return 'document';
});

const isImage = computed(
    () => mediaCategory.value === 'image'
);

const isVideo = computed(
    () => mediaCategory.value === 'video'
);

const isAudio = computed(
    () => mediaCategory.value === 'audio'
);

const isPreviewable = computed(
    () =>
        isImage.value ||
        isVideo.value ||
        isAudio.value
);

const displayName = computed(
    () =>
        props.document.original_filename ||
        props.document.stored_filename ||
        'Attachment'
);

const formattedSize = computed(() => {
    if (props.document.formatted_size) {
        return props.document.formatted_size;
    }

    const bytes = Number(
        props.document.size || 0
    );

    if (!bytes) {
        return 'Unknown size';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1048576) {
        return `${(
            bytes / 1024
        ).toFixed(1)} KB`;
    }

    return `${(
        bytes / 1048576
    ).toFixed(1)} MB`;
});

const previewUrl = computed(
    () =>
        props.document.preview_url ||
        route(
            'documents.preview',
            props.document.id
        )
);

const downloadUrl = computed(
    () =>
        props.document.download_url ||
        route(
            'documents.download',
            props.document.id
        )
);

const icon = computed(() => {
    if (mediaCategory.value === 'pdf') {
        return '📄';
    }

    if (isImage.value) {
        return '🖼️';
    }

    if (isVideo.value) {
        return '🎥';
    }

    if (isAudio.value) {
        return '🎵';
    }

    return '📎';
});

const iconBg = computed(() => {
    if (mediaCategory.value === 'pdf') {
        return 'bg-red-50';
    }

    if (isImage.value) {
        return 'bg-blue-50';
    }

    if (isVideo.value) {
        return 'bg-indigo-50';
    }

    if (isAudio.value) {
        return 'bg-purple-50';
    }

    return 'bg-surface-100';
});

function openPreview() {
    window.open(
        previewUrl.value,
        '_blank',
        'noopener,noreferrer'
    );
}

const DownloadIcon = defineComponent({
    setup() {
        return () =>
            h(
                'svg',
                {
                    class:
                        'ml-auto h-4 w-4 shrink-0 text-surface-300 transition-colors group-hover:text-brand-500',

                    fill: 'none',
                    stroke: 'currentColor',
                    viewBox: '0 0 24 24',
                },
                [
                    h('path', {
                        'stroke-linecap':
                            'round',

                        'stroke-linejoin':
                            'round',

                        'stroke-width': '2',

                        d:
                            'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
                    }),
                ]
            );
    },
});

const FileFooter = defineComponent({
    props: {
        class: {
            type: String,
            default: '',
        },
    },

    setup(componentProps) {
        return () =>
            h(
                'div',
                {
                    class: [
                        'flex items-center gap-2 px-3 py-2',
                        componentProps.class,
                    ],
                },
                [
                    h(
                        'div',
                        {
                            class:
                                'min-w-0 flex-1',
                        },
                        [
                            h(
                                'p',
                                {
                                    class:
                                        'truncate text-xs font-medium text-surface-800',
                                },
                                displayName.value
                            ),

                            h(
                                'p',
                                {
                                    class:
                                        'text-[10px] text-surface-400',
                                },
                                formattedSize.value
                            ),
                        ]
                    ),
                ]
            );
    },
});
</script>
