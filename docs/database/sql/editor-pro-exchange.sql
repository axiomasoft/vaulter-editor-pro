-- Package axiomasoft/vaulter-editor-pro-exchange (V5 / Phase 10).
-- Apply after documents.sql and media.sql on the owner DB connection, PostgreSQL 18.
BEGIN;

CREATE TABLE v_document_exchange_jobs (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id bigint NOT NULL,
    project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
    effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    request_key varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(request_key) BETWEEN 1 AND 255),
    request_fingerprint bytea NOT NULL CHECK (octet_length(request_fingerprint) = 32),
    kind varchar(16) NOT NULL CHECK (kind IN ('import', 'export')),
    format varchar(16) NOT NULL CHECK (format IN ('docx')),
    profile_id varchar(64) NOT NULL CHECK (profile_id <> ''),
    source_blob_id uuid REFERENCES v_blobs(id) ON DELETE RESTRICT,
    source_revision_id uuid REFERENCES v_document_revisions(id) ON DELETE RESTRICT,
    source_generation bigint CHECK (source_generation BETWEEN 0 AND 9007199254740991),
    source_position bigint CHECK (source_position BETWEEN 0 AND 9007199254740991),
    output_blob_id uuid REFERENCES v_blobs(id) ON DELETE RESTRICT,
    apply_command_id varchar(255) COLLATE "C" CHECK (octet_length(apply_command_id) BETWEEN 1 AND 255),
    apply_receipt_id uuid,
    state varchar(20) NOT NULL DEFAULT 'queued' CHECK (state IN (
        'queued', 'running', 'preview_ready', 'completed', 'applied', 'failed'
    )),
    diagnostics_report jsonb CHECK (diagnostics_report IS NULL OR jsonb_typeof(diagnostics_report) = 'object'),
    error_code varchar(64),
    lease_token uuid,
    lease_until timestamptz,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at timestamptz,
    UNIQUE (tenant_id, project_scope, effective_subject, document_id, request_key),
    CHECK ((lease_token IS NULL) = (lease_until IS NULL)),
    CHECK ((kind = 'import' AND source_blob_id IS NOT NULL)
        OR (kind = 'export' AND source_generation IS NOT NULL AND source_position IS NOT NULL)),
    CHECK ((state <> 'completed' OR (kind = 'export' AND output_blob_id IS NOT NULL))
        AND (state <> 'applied' OR (kind = 'import' AND apply_command_id IS NOT NULL AND apply_receipt_id IS NOT NULL)))
);
CREATE INDEX v_document_exchange_jobs_queue
    ON v_document_exchange_jobs (state, lease_until, created_at) WHERE state IN ('queued', 'running');
CREATE INDEX v_document_exchange_jobs_doc
    ON v_document_exchange_jobs (document_id, created_at DESC);
CREATE INDEX v_document_exchange_jobs_source_blob_fk_idx
    ON v_document_exchange_jobs (source_blob_id);
CREATE INDEX v_document_exchange_jobs_source_rev_fk_idx
    ON v_document_exchange_jobs (source_revision_id);
CREATE INDEX v_document_exchange_jobs_output_blob_fk_idx
    ON v_document_exchange_jobs (output_blob_id);

COMMIT;
