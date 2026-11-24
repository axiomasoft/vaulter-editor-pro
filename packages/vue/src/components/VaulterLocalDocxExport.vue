<script setup lang="ts">
import { computed } from 'vue'
import type {
  DocxDiscussionInput,
  DocxProfileId,
  DocxProjectionMode,
  DocxSectionInput,
  LossReport,
  TipTapDoc,
} from '@vaulter/editor-pro-docx/browser'
import {
  useLocalDocxExport,
  type LocalDocxExportOutput,
} from '../composables/useLocalDocxExport'
import type { AnchoredComment, RevisionMeta } from '../types/review'

const props = withDefaults(
  defineProps<{
    content: TipTapDoc | Record<string, unknown> | null
    revision?: RevisionMeta | null
    discussions?: Array<AnchoredComment | DocxDiscussionInput>
    sections?: DocxSectionInput[]
    title?: string | null
    creator?: string | null
    filename?: string
    confirmed?: boolean
    accessible?: boolean
    enableDiscussions?: boolean
    enableRevisions?: boolean
    requireFormattingRevisions?: boolean
    defaultProfile?: DocxProfileId
    defaultProjection?: DocxProjectionMode
    workerFactory?: () => Worker
    autoPreview?: boolean
    autoDownload?: boolean
  }>(),
  {
    revision: null,
    discussions: undefined,
    sections: undefined,
    title: null,
    creator: null,
    filename: undefined,
    confirmed: true,
    accessible: true,
    enableDiscussions: true,
    enableRevisions: false,
    requireFormattingRevisions: undefined,
    defaultProfile: undefined,
    defaultProjection: undefined,
    workerFactory: undefined,
    autoPreview: true,
    autoDownload: true,
  },
)

const emit = defineEmits<{
  previewed: [report: LossReport]
  exported: [result: LocalDocxExportOutput]
  blocked: [report: LossReport]
  error: [error: unknown]
}>()

function createDefaultWorkerFactory(): (() => Worker) | undefined {
  if (typeof Worker !== 'function') {
    return undefined
  }
  return () => {
    const base =
      typeof import.meta.url === 'string' && import.meta.url.includes('://')
        ? import.meta.url
        : 'http://localhost/'
    return new Worker(new URL('../docx/localExportWorker.ts', base), { type: 'module' })
  }
}

const {
  profile,
  projection,
  availableProfiles,
  sourceMarker,
  isConfirmedAndAccessible,
  previewReport,
  previewSemantic,
  lastResult,
  exporting,
  error,
  preview,
  exportLocal,
  download,
} = useLocalDocxExport({
  source: () => ({
    content: props.content,
    revision: props.revision,
    discussions: props.discussions,
    sections: props.sections,
    title: props.title,
    creator: props.creator,
    filename: props.filename,
    confirmed: props.confirmed,
    accessible: props.accessible,
  }),
  defaultProfile: props.defaultProfile,
  defaultProjection: props.defaultProjection,
  enableDiscussions: props.enableDiscussions,
  enableRevisions: props.enableRevisions,
  requireFormattingRevisions: props.requireFormattingRevisions,
  workerFactory: props.workerFactory ?? createDefaultWorkerFactory(),
  autoPreview: props.autoPreview,
})

const canExport = computed<boolean>(
  () =>
    isConfirmedAndAccessible.value &&
    !exporting.value &&
    !(previewReport.value?.blocked ?? false),
)

async function handlePreview(): Promise<LossReport | null> {
  const report = await preview()
  if (report) {
    emit('previewed', report)
    if (report.blocked) {
      emit('blocked', report)
    }
  }
  return report
}

async function handleExport(): Promise<LocalDocxExportOutput | null> {
  try {
    const result = props.autoDownload ? await download() : await exportLocal()
    emit('exported', result)
    return result
  } catch (caught) {
    if (previewReport.value?.blocked) {
      emit('blocked', previewReport.value)
    }
    emit('error', caught)
    return null
  }
}

defineExpose({
  profile,
  projection,
  availableProfiles,
  sourceMarker,
  isConfirmedAndAccessible,
  previewReport,
  previewSemantic,
  lastResult,
  exporting,
  error,
  canExport,
  preview: handlePreview,
  exportLocal: handleExport,
})
</script>

<template>
  <section
    class="vaulter-local-docx-export"
    aria-label="Local offline DOCX export"
    :data-confirmed="confirmed ? 'true' : 'false'"
    :data-accessible="accessible ? 'true' : 'false'"
    :data-export-blocked="previewReport?.blocked ? 'true' : 'false'"
  >
    <header class="vaulter-local-docx-export__header">
      <div class="vaulter-local-docx-export__version">
        <span v-if="isConfirmedAndAccessible" data-status="confirmed">
          Confirmed version
          <template v-if="revision?.number !== undefined"> #{{ revision.number }}</template>
          <template v-else-if="revision?.id"> ({{ revision.id }})</template>
        </span>
        <span v-else data-status="unavailable">
          {{ accessible === false ? 'Access revoked' : 'Unconfirmed version' }}
        </span>
      </div>

      <div class="vaulter-local-docx-export__controls">
        <label class="vaulter-local-docx-export__field">
          <span>Profile</span>
          <select
            v-model="profile"
            data-control="profile"
            :disabled="!isConfirmedAndAccessible || exporting"
          >
            <option
              v-for="item in availableProfiles"
              :key="item.profile"
              :value="item.profile"
            >
              {{ item.label }}
            </option>
          </select>
        </label>

        <label v-if="enableRevisions" class="vaulter-local-docx-export__field">
          <span>Projection</span>
          <select
            v-model="projection"
            data-control="projection"
            :disabled="!isConfirmedAndAccessible || exporting"
          >
            <option value="original">Original (accepted)</option>
            <option value="proposed">Proposed</option>
            <option value="review">Review (tracked)</option>
          </select>
        </label>

        <button
          type="button"
          data-action="preview-docx"
          :disabled="!isConfirmedAndAccessible || exporting"
          @click="handlePreview"
        >
          Refresh Preview
        </button>

        <button
          type="button"
          data-action="export-docx"
          :disabled="!canExport"
          @click="handleExport"
        >
          {{ exporting ? 'Exporting…' : 'Export DOCX (Offline)' }}
        </button>
      </div>
    </header>

    <p v-if="error" class="vaulter-local-docx-export__error" role="alert">
      {{ error.message }}
    </p>

    <div
      v-if="previewReport"
      class="vaulter-local-docx-export__report"
      :data-profile="previewReport.profile"
      :data-export-blocked="previewReport.blocked ? 'true' : 'false'"
    >
      <div class="vaulter-local-docx-export__summary">
        <span data-summary="info">Info: {{ previewReport.summary.info }}</span>
        <span data-summary="warning">Warnings: {{ previewReport.summary.warning }}</span>
        <span data-summary="error">Errors: {{ previewReport.summary.error }}</span>
      </div>

      <p
        v-if="previewReport.blocked"
        class="vaulter-local-docx-export__blocked"
        role="alert"
      >
        Export blocked for profile "{{ previewReport.profile }}":
        {{ previewReport.blocking_reasons.join('; ') }}
      </p>

      <ul
        v-if="previewReport.diagnostics.length > 0"
        class="vaulter-local-docx-export__diagnostics"
      >
        <li
          v-for="(diag, index) in previewReport.diagnostics"
          :key="`${diag.code}-${index}`"
          class="vaulter-local-docx-export__diagnostic"
          :data-diagnostic-code="diag.code"
          :data-severity="diag.severity"
          :data-action="diag.action"
        >
          <strong>[{{ diag.severity }}] {{ diag.code }}</strong>
          <span> ({{ diag.feature }} @ {{ diag.location }})</span>:
          {{ diag.message }}
        </li>
      </ul>
      <p v-else class="vaulter-local-docx-export__clean" data-clean="true">
        No compatibility warnings for profile "{{ previewReport.profile }}".
      </p>
    </div>
  </section>
</template>
