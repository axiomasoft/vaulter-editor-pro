> Clean-room specification migrated into this repository. Paths that pointed at the retired planning tree now point at this repository.

# База данных закрытого `vaulter-editor-pro`

Два исполняемых SQL-файла задают целевую схему для пакетов `vaulter-editor-pro` на соединении владельца (`OWNER`, рядом с `Documents` и `Media`). Все таблицы используют префикс `v_`, UUIDv7 и стандартные правила [владения схемой](README.md).

- [Review DDL, 3 таблицы](sql/editor-pro-review.sql) (`v_document_revision_pins`, `v_document_comment_anchors`, `v_document_suggestions`)
- [Exchange DDL, 1 таблица](sql/editor-pro-exchange.sql) (`v_document_exchange_jobs`)
- [Спецификация реализации](../implementation/editor-pro.md)

| Дистрибутив | Путь миграции в `vaulter-editor-pro` | Зависимости | Роль |
| --- | --- | --- | --- |
| `axiomasoft/vaulter-editor-pro-review` | `packages/review/database/migrations/0001_01_01_000800_create_editor_pro_review_tables.php` | `vaulter-documents` | Удержание ревизий при compaction, generation-scoped диапазонные якоря к `v_node_comments` и таблица предложений `v_document_suggestions` |
| `axiomasoft/vaulter-editor-pro-exchange` | `packages/exchange/database/migrations/0001_01_01_000801_create_editor_pro_exchange_tables.php` | `vaulter-documents`, `vaulter-media` | Серверные задания импорта/экспорта DOCX, аренда worker, отчёт потерь и связь с Media blobs и `apply_receipt_id` |

## Таблицы и ключевые инварианты

| Таблица | Ключи и ограничения | Инвариант |
| --- | --- | --- |
| `v_document_revision_pins` | `PK(revision_id)` → `v_document_revisions(id) ON DELETE CASCADE`, `document_id` → `v_documents(id)` | Компактация журнала коллаборации и очистка старых ревизий не удаляют закреплённую ревизию, пока существует активный pin (`expires_at IS NULL OR expires_at > now`) |
| `v_document_comment_anchors` | `PK(id)`, `UNIQUE(comment_id)` → `v_node_comments(id) ON DELETE CASCADE`, индекс `(document_id, generation, anchor_state, block_id)` | Расширяет корневые комментарии узла диапазонной привязкой (`snapshot_utf16` или `yjs_relative`). Комментарии без range продолжают работать без строк в этой таблице. Restore переводит старые якоря в `detached`/`historical` |
| `v_document_suggestions` | `PK(id)`, индекс `(document_id, generation, group_id) WHERE state = 'open'`, индекс `(proposal_id)` | `state='accepted'` требует `decided_by_subject`, `decided_at` и `receipt_id` владельца; `state='open'` запрещает `receipt_id`; `group_id` объединяет парные части `replace` |
| `v_document_exchange_jobs` | `PK(id)`, `UNIQUE(tenant_id, project_scope, effective_subject, document_id, request_key)`, FK на `v_blobs` (`ON DELETE RESTRICT`) | `kind='import'` требует `source_blob_id`; `kind='export'` требует `source_generation` и `source_position`; `state='completed'` требует `output_blob_id`; `state='applied'` требует `apply_command_id` и `apply_receipt_id`. `lease_token` и `lease_until` задаются только парой |

## Проверка схемы

Схема компилируется и проверяется в изолированной БД `editor_pro_owner` (35 таблиц = 31 базовых + 4 таблицы `editor-pro`, без AI и Sheets) и в общей БД `office` (72 таблицы) командой:

```bash
psql -v ON_ERROR_STOP=1 -f docs/database/sql/editor-pro-review.sql
psql -v ON_ERROR_STOP=1 -f docs/database/sql/editor-pro-exchange.sql
```
