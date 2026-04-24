<template>
    <div class="relative h-40">
        <svg class="w-full h-full" :viewBox="`0 0 ${width} ${height}`" preserveAspectRatio="none">
            <!-- Grid lines -->
            <line v-for="i in 4" :key="i" x1="0" :y1="(height / 4) * i" :x2="width" :y2="(height / 4) * i"
                stroke="#f1f5f9" stroke-width="1" />
            <!-- Bars -->
            <g v-for="(item, i) in paddedData" :key="i">
                <!-- Inbound bar -->
                <rect :x="barX(i)" :y="height - barH(item.inbound ?? 0)" :width="barW * 0.45"
                    :height="barH(item.inbound ?? 0)" rx="3" class="fill-brand-400 opacity-80" />
                <!-- Outbound bar -->
                <rect :x="barX(i) + barW * 0.48" :y="height - barH(item.outbound ?? 0)" :width="barW * 0.45"
                    :height="barH(item.outbound ?? 0)" rx="3" class="fill-blue-400 opacity-80" />
            </g>
        </svg>
        <!-- X labels -->
        <div class="absolute bottom-0 left-0 right-0 flex justify-around -mb-5">
            <span v-for="(item, i) in paddedData" :key="i" class="text-[9px] text-surface-400 text-center">
                {{ formatLabel(item.date) }}
            </span>
        </div>
        <!-- Legend -->
        <div class="absolute top-0 right-0 flex gap-3">
            <span class="flex items-center gap-1 text-[10px] text-surface-400">
                <span class="w-2 h-2 rounded-sm bg-brand-400 inline-block" /> Inbound
            </span>
            <span class="flex items-center gap-1 text-[10px] text-surface-400">
                <span class="w-2 h-2 rounded-sm bg-blue-400 inline-block" /> Outbound
            </span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { format, subDays, parseISO } from 'date-fns';

const props = defineProps({ data: { type: Array, default: () => [] } });

const width = 400;
const height = 120;

// Always show last 7 days
const paddedData = computed(() => {
    const days = Array.from({ length: 7 }, (_, i) => {
        const d = format(subDays(new Date(), 6 - i), 'yyyy-MM-dd');
        const found = props.data.find(x => x.date === d);
        return found ?? { date: d, inbound: 0, outbound: 0 };
    });
    return days;
});

const maxVal = computed(() => Math.max(...paddedData.value.map(d => (d.inbound ?? 0) + (d.outbound ?? 0)), 1));
const barW = computed(() => width / paddedData.value.length);

function barX(i) { return i * barW.value + barW.value * 0.05; }
function barH(v) { return (v / maxVal.value) * (height - 10); }
function formatLabel(d) {
    try { return format(parseISO(d), 'MM/dd'); } catch { return d; }
}
</script>