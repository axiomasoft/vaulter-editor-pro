<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { DocxProfileId, LossReport } from '@vaulter/editor-pro-docx/browser'
import type { ReviewClient } from '../api/reviewClient'
import { useDocxExchange } from '../composables/useDocxExchange'
import type { ApplyExchangeReceipt, ExchangeJob } from '../types/review'

const props = withDefaults(
  defineProps<{
    nodeId: string
    client?: ReviewClient
    initialJobId?: string | null
    storageKey?: string
    pollIntervalMs?: number
    defaultProfile?: DocxProfileId
    currentGeneration?: number | null
    currentPosition?: number | null
    autoReconnect?: boolean
  }>(),
  {
    client: undefined,
    initialJobId: null,
    storageKey: undefined,
    pollIntervalMs: 1200,
    defaultProfile: 'discussions',
    currentGeneration: null,
    currentPosition: null,
    autoReconnect: true,
  },
)

const emit = defineEmits<{
  registered: [job: ExchangeJob]
  reconnected: [job: ExchangeJob | null]
  applied: [receipt: ApplyExchangeReceipt]
  downloaded: [blob: Blob]
  error: [error: unknown]
}>()

const selectedFile = ref<File | null>(null)

const {
  job,
  jobId,
  profile,
  loading,
  polling,
  applying,
  applyConfirmed,
  applyReceipt,
  error,
  diagnosticsReport,
  previewDocument,
  canApply,
  canDownload,
  uploadFile,
  registerExport,
  reconnect,
  refreshStatus,
  applyImport,
  downloadExport,
  stopPolling,
} = useDocxExchange({
  nodeId: () => props.nodeId,
  client: props.client,
  initialJobId: props.initialJobId,
  storageKey: props.storageKey,
  pollIntervalMs: props.pollIntervalMs,
  defaultProfile: props.defaultProfile,
})

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement | null
  selectedFile.value = input?.files?.[0] ?? null
}

async function handleUpload(): Promise<ExchangeJob | null> {
  if (!selectedFile.value) {
    return null
  }
  try {
    const created = await uploadFile(selectedFile.value, {
      profileId: profile.value,
      sourceGeneration: props.currentGeneration,
      sourcePosition: props.currentPosition,
    })
    emit('registered', created)
    return created
  } catch (caught) {
    emit('error', caught)
    return null
  }
}

async function handleExport(): Promise<ExchangeJob | null> {
  try {
    const created = await registerExport({
      request_key: `exp-docx-${Date.now()}`,
      profile_id: profile.value,
      ...(props.currentGeneration !== null ? { source_generation: props.currentGeneration } : {}),
      ...(props.currentPosition !== null ? { source_position: props.currentPosition } : {}),
    })
    emit('registered', created)
    return created
  } catch (caught) {
    emit('error', caught)
    return null
  }
}

async function handleReconnect(): Promise<ExchangeJob | null> {
  try {
    const recovered = await reconnect()
    emit('reconnected', recovered)
    return recovered
  } catch (caught) {
    emit('error', caught)
    return null
  }
}

async function handleApply(): Promise<ApplyExchangeReceipt | null> {
  try {
    const receipt = await applyImport()
    emit('applied', receipt)
    return receipt
  } catch (caught) {
    emit('error', caught)
    return null
  }
}

async function handleDownload(): Promise<Blob | null> {
  try {
    const blob = await downloadExport()
    emit('downloaded', blob)
    return blob
  } catch (caught) {
    emit('error', caught)
    return null
  }
}

onMounted(() => {
  if (props.autoReconnect && jobId.value) {
    void handleReconnect()
  }
})

defineExpose({
  job,
  jobId,
  profile,
  loading,
  polling,
  applying,
  applyConfirmed,
  applyReceipt,
  error,
  diagnosticsReport,
  previewDocument,
  canApply,
  canDownload,
  upload: handleUpload,
  exportDocx: handleExport,
  reconnect: handleReconnect,
  refresh: refreshStatus,
  apply: handleApply,
  download: handleDownload,
  stopPolling,
})
</script>

<template>
  <section
    class="vaulter-docx-exchange"
    aria-label="Server DOCX exchange"
    :data-job-id="job?.id ?? jobId ?? ''"
    :data-job-state="job?.state ?? 'idle'"
    :data-polling="polling ? 'true' : 'false'"
  >
    <header class="vaulter-docx-exchange__controls">
      <label class="vaulter-docx-exchange__field">
        <span>DOCX File</span>
        <input
          type="file"
          accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
          data-control="file"
          :disabled="loading || applying"
          @change="onFileChange"
        />
      </label>

      <label class="vaulter-docx-exchange__field">
        <span>Profile</span>
        <select v-model="profile" data-control="profile" :disabled="loading || applying">
          <option value="standard">Standard</option>
          <option value="discussions">Discussions</option>
          <option value="legacy-discussions">Legacy Discussions</option>
          <option value="revisions">Revisions</option>
          <option value="strict-ooxml">Strict OOXML</option>
        </select>
      </label>

      <button
        type="button"
        data-action="upload-import"
        :disabled="!selectedFile || loading || applying"
        @click="handleUpload"
      >
        Upload DOCX Preview
      </button>

      <button
        type="button"
        data-action="start-export"
        :disabled="loading || applying"
        @click="handleExport"
      >
        Export DOCX
      </button>

      <button
        v-if="jobId"
        type="button"
        data-action="reconnect-job"
        :disabled="loading"
        @click="handleReconnect"
      >
        Reconnect Status
      </button>
    </header>

    <p v-if="error" class="vaulter-docx-exchange__error" role="alert" data-error="true">
      {{ error.message }}
    </p>

    <div v-if="job" class="vaulter-docx-exchange__status" :data-state="job.state">
      <strong>Job {{ job.id }}</strong>
      <span data-status="state"> — {{ job.state }}</span>
      <span v-if="job.error_code" data-status="error-code"> ({{ job.error_code }})</span>

      <div v-if="diagnosticsReport" class="vaulter-docx-exchange__report">
        <div class="vaulter-docx-exchange__summary">
          <span data-summary="info">Info: {{ (diagnosticsReport as LossReport).summary.info }}</span>
          <span data-summary="warning">Warnings: {{ (diagnosticsReport as LossReport).summary.warning }}</span>
          <span data-summary="error">Errors: {{ (diagnosticsReport as LossReport).summary.error }}</span>
        </div>

        <p
          v-if="(diagnosticsReport as LossReport).blocked"
          class="vaulter-docx-exchange__blocked"
          role="alert"
        >
          Blocked: {{ (diagnosticsReport as LossReport).blocking_reasons.join('; ') }}
        </p>

        <ul
          v-if="(diagnosticsReport as LossReport).diagnostics.length > 0"
          class="vaulter-docx-exchange__diagnostics"
        >
          <li
            v-for="(diag, index) in (diagnosticsReport as LossReport).diagnostics"
            :key="`${diag.code}-${index}`"
            :data-diagnostic-code="diag.code"
            :data-severity="diag.severity"
          >
            [{{ diag.severity }}] {{ diag.code }} ({{ diag.feature }}): {{ diag.message }}
          </li>
        </ul>
      </div>

      <div v-if="previewDocument" class="vaulter-docx-exchange__preview" data-preview-ready="true">
        Preview ready ({{ Array.isArray(previewDocument.content) ? previewDocument.content.length : 0 }} top-level blocks).
      </div>

      <div
        v-if="job.kind === 'import' && job.state === 'preview_ready'"
        class="vaulter-docx-exchange__apply"
      >
        <label class="vaulter-docx-exchange__confirm">
          <input
            v-model="applyConfirmed"
            type="checkbox"
            data-control="confirm-apply"
            :disabled="applying || Boolean(diagnosticsReport?.blocked)"
          />
          <span>Confirm replacing the current document head with this DOCX preview.</span>
        </label>

        <button
          type="button"
          data-action="apply-import"
          :disabled="!canApply"
          @click="handleApply"
        >
          {{ applying ? 'Applying…' : 'Confirm & Apply Import' }}
        </button>
      </div>

      <div v-if="applyReceipt" class="vaulter-docx-exchange__receipt" data-applied="true">
        Applied receipt: {{ applyReceipt.apply_receipt_id ?? 'pending' }}
        (command {{ applyReceipt.apply_command_id }}).
      </div>

      <div v-if="canDownload" class="vaulter-docx-exchange__download">
        <button
          type="button"
          data-action="download-export"
          @click="handleDownload"
        >
          Download Exported DOCX
        </button>
      </div>
    </div>
  </section>
</template>
