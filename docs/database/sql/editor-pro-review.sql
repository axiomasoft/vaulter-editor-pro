-- Package axiomasoft/vaulter-editor-pro-review (V3 / Phase 08).
-- Apply after documents.sql on the owner DB connection, PostgreSQL 18.
BEGIN;

CREATE TABLE v_document_revision_pins (
    revision_id uuid PRIMARY KEY REFERENCES v_document_revisions(id) ON DELETE CASCADE,
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    tenant_id bigint NOT NULL,
    pin_reason varchar(32) NOT NULL CHECK (pin_reason IN ('manual_version', 'review_base', 'comment_history', 'exchange_source')),
    source_generation bigint NOT NULL CHECK (source_generation BETWEEN 0 AND 9007199254740991),
    source_position bigint NOT NULL CHECK (source_position BETWEEN 0 AND 9007199254740991),
    pinned_by_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(pinned_by_subject) BETWEEN 1 AND 255),
    pinned_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamptz,
    CHECK (expires_at IS NULL OR expires_at > pinned_at)
);
CREATE INDEX v_document_revision_pins_doc ON v_document_revision_pins (document_id, pinned_at DESC);

CREATE TABLE v_document_comment_anchors (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id bigint NOT NULL,
    comment_id uuid NOT NULL UNIQUE REFERENCES v_node_comments(id) ON DELETE CASCADE,
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    generation bigint NOT NULL CHECK (generation BETWEEN 0 AND 9007199254740991),
    source_position bigint NOT NULL CHECK (source_position BETWEEN 0 AND 9007199254740991),
    schema_version integer NOT NULL CHECK (schema_version > 0),
    block_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(block_id) BETWEEN 1 AND 255),
    selector_kind varchar(24) NOT NULL CHECK (selector_kind IN ('snapshot_utf16', 'yjs_relative')),
    selector_payload jsonb NOT NULL CHECK (jsonb_typeof(selector_payload) = 'object'),
    quote_text text NOT NULL,
    anchor_state varchar(16) NOT NULL DEFAULT 'attached' CHECK (anchor_state IN ('attached', 'detached', 'historical')),
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX v_document_comment_anchors_doc
    ON v_document_comment_anchors (document_id, generation, anchor_state, block_id);

CREATE TABLE v_document_suggestions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id bigint NOT NULL,
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    group_id uuid NOT NULL,
    proposal_id uuid,
    generation bigint NOT NULL CHECK (generation BETWEEN 0 AND 9007199254740991),
    base_position bigint NOT NULL CHECK (base_position BETWEEN 0 AND 9007199254740991),
    schema_version integer NOT NULL CHECK (schema_version > 0),
    author_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(author_subject) BETWEEN 1 AND 255),
    provenance_json jsonb CHECK (provenance_json IS NULL OR jsonb_typeof(provenance_json) = 'object'),
    kind varchar(24) NOT NULL CHECK (kind IN (
        'insert', 'delete', 'replace', 'mark_change', 'attr_change', 'block_split', 'block_join', 'list_change', 'table_change'
    )),
    target_block_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(target_block_id) BETWEEN 1 AND 255),
    target_selector jsonb NOT NULL CHECK (jsonb_typeof(target_selector) = 'object'),
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    state varchar(16) NOT NULL DEFAULT 'open' CHECK (state IN ('open', 'accepted', 'rejected', 'superseded')),
    decided_by_subject varchar(255) COLLATE "C" CHECK (octet_length(decided_by_subject) BETWEEN 1 AND 255),
    decided_at timestamptz,
    receipt_id uuid,
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((state = 'open' AND decided_by_subject IS NULL AND decided_at IS NULL AND receipt_id IS NULL)
        OR (state = 'accepted' AND decided_by_subject IS NOT NULL AND decided_at IS NOT NULL AND receipt_id IS NOT NULL)
        OR (state IN ('rejected', 'superseded') AND decided_at IS NOT NULL AND receipt_id IS NULL))
);
CREATE INDEX v_document_suggestions_doc_open
    ON v_document_suggestions (document_id, generation, group_id) WHERE state = 'open';
CREATE INDEX v_document_suggestions_doc_fk_idx
    ON v_document_suggestions (document_id, generation);
CREATE INDEX v_document_suggestions_proposal
    ON v_document_suggestions (proposal_id) WHERE proposal_id IS NOT NULL;

COMMIT;
