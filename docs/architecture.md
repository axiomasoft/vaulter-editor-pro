> Clean-room specification migrated into this repository. Paths that pointed at the retired planning tree now point at this repository.

# Закрытый репозиторий `vaulter-editor-pro`

Этот документ — каноническая спецификация реализации репозитория `vaulter-editor-pro` (History Preview/Compare, Range Comments, Tracked Changes, DOCX Import/Export). Закрытые каталоги и исследовательские выгрузки в репозиторий не переносятся. **Источником требований является этот документ** вместе с `docs/database.md` и SQL в `docs/database/sql/`.

Цель — собственная независимая реализация редакторских возможностей (History Preview/Compare, Range Comments, Tracked Changes / Structural Review, DOCX Import/Export) на открытом стеке (Tiptap OSS, ProseMirror, Yjs, MIT `docx`/XML/ZIP) **без каких-либо runtime-зависимостей от `registry.tiptap.dev` или Tiptap Cloud**.

---

## 1. Структура репозитория и устанавливаемые пакеты

| Путь | Пакет (Composer / npm) | Namespace / Exports | Ответственность |
| --- | --- | --- | --- |
| `packages/review/` | `axiomasoft/vaulter-editor-pro-review` | `Vaulter\EditorPro\Review\`, `EditorProReviewServiceProvider` | PHP-владелец `v_document_revision_pins`, `v_document_comment_anchors`, `v_document_suggestions`; расширение `v_node_comments`, CAS resolve/reopen/reanchor, атомарный accept/reject через `AtomicDocumentMutation` (snapshot) и `CommandJournal` (journal) |
| `packages/exchange/` | `axiomasoft/vaulter-editor-pro-exchange` | `Vaulter\EditorPro\Exchange\`, `EditorProExchangeServiceProvider` | PHP-владелец `v_document_exchange_jobs`; маршруты `exchange-jobs`, `preview`, `file`, `apply`; удержание Media blobs, lease/fencing, защита от zip-bomb/XXE/external rels |
| `packages/docx/` | `@vaulter/editor-pro-docx` | ESM/CJS (`exportDocx`, `importDocx`, `toIntermediate`, `fromIntermediate`) | Версионированный mapping `Tiptap JSON/review ↔ intermediate model ↔ OOXML`, реестр единиц, отчёт потерь `{profile, source_marker, diagnostics}`, офлайн-экспорт в браузере и Node worker |
| `packages/vue/` | `@vaulter/editor-pro-vue` | `VaulterHistoryCompare`, `VaulterRangeComments`, `VaulterTrackedChanges`, `VaulterDocxExchange`, Tiptap extensions | Vue 3 + Tiptap OSS расширения: read-only Preview/Compare в отдельном `EditorView`, Range Comments sidebar, Tracked Changes review overlay (`original`/`proposed`/`review`), UI DOCX |
| `tests/` | — | — | Корпус DOCX D1–D4, фикстуры `COMMENTS`, `REVIEW-HISTORY`, `REVIEW`, `DOCX`, `FILES`, изолированный consumer + Playwright E2E |

---

## 2. Фаза 8.1: History Preview/Compare и Range Comments

### 2.1. Разделение трёх сущностей
Нельзя сводить обсуждение, привязку к тексту и статус в один HTML span:
1. **Discussion** — существующая корневая запись `v_node_comments` (`parent_id IS NULL`) + ответы (`parent_id = root_id`), авторство строго из сеанса, ACL узла.
2. **Anchor** — отдельная запись `v_document_comment_anchors` (`generation`, `source_position`, `schema_version`, `block_id`, `selector_kind`: `snapshot_utf16` | `yjs_relative`, `selector_payload`, `quote_text`, `anchor_state`: `attached` | `detached` | `historical`, `version`).
3. **Revision Pin** — `v_document_revision_pins`, удерживающий неизменяемый payload пользовательской ревизии при compaction хвоста журнала.

### 2.2. Правила жизненного цикла якорей
- **Вставка перед диапазоном / сдвиг:** якорь следует за текстом (`yjs_relative` в journal; однозначный remap по `block_id` + UTF-16 + контекст в snapshot).
- **Вставка на границе (`assoc`-геометрия):** левый якорь `assoc = 0` (первый символ внутри), правый `assoc = -1` (последний символ внутри), чтобы ввод вплотную снаружи границ не расширял комментарий.
- **Обход бага `@tiptap/y-tiptap@3.0.10 #8614` (`pos === 1`):** `relativePositionToAbsolutePosition` возвращает `null` для валидного якоря на первом символе документа (например, после удаления текста перед комментарием). Обязательный защитный хелпер `safeResolveRelativePos`: при `null` вызывать `Y.createAbsolutePositionFromRelativePosition(relPos, ydoc)` и, если вернулся валидный `Y.XmlText` с `index === 0` в первом блоке, возвращать `pos = 1`.
- **Частичное удаление:** диапазон сужается, discussion и replies сохраняются.
- **Полное удаление цели или неоднозначный remap в snapshot:** `anchor_state = detached`; discussion и replies **не удаляются**, сохраняются `quote_text` и `source_position`. Доступна явная команда `reanchor`.
- **Copy/paste текста:** по умолчанию текст копируется без discussion identity.
- **Restore документа (`G → G+1`):** текст восстанавливается в новом поколении; старые якоря переводятся в `detached`/`historical` (даже при совпадении `block_id`), discussion и replies сохраняются по ACL.
- **Защита авторства:** произвольный Yjs update не может подделать `author_subject` или изменить чужой comment; все мутации комментариев и якорей идут через авторизованные PHP-команды (`nodes/{node}/comments`, `reopen`, `reanchor`, CAS по `version`).

### 2.3. Read-only Preview и Structural Compare
- Preview и Compare монтируются в **отдельном изолированном `EditorView`** поверх двух неизменяемых ревизий (никогда не вызывать `setContent` с diff на активном пишущем редакторе).
- Гарантия: **0 live updates** и 0 cleanup callbacks.
- Structural diff реализуется двухэтапно на **`prosemirror-changeset >= 2.4.4`** (MIT): этап 1 — выравнивание блоков верхнего уровня, элементов списков и строк таблиц по `attrs.blockId` (fallback для узлов без `blockId`: LCS по сигнатуре типа + текстовый `simhash`); этап 2 — `ChangeSet` внутри пар с кодированием активных marks в токены + декорации `attr_change` (`level`, `colwidth`, `align`).

---

## 3. Фазы 8.2 и 8.3: Tracked Changes и Structural Review

### 3.1. Гибридная модель хранения и три проекции
- Хранение: `v_document_suggestions` (`id`, `document_id`, `group_id`, `proposal_id`, `generation`, `base_position`, `schema_version`, `author_subject`, `provenance_json`, `kind`, `target_block_id`, `target_selector`, `payload`, `state`, `version`, `receipt_id`).
- **Гибридная схема:** AI-предложения `P/Q` до одобрения хранятся строго во внешней таблице (никогда не мутируют `Y.Doc`); живые Tracked Changes хранят в `Y.Doc` только минимальные маркеры `{ suggestionId, kind, parentId }`, тогда как авторитетные `author_subject`, время и права accept/reject хранятся исключительно в PostgreSQL `v_document_suggestions`.
- Связь с AI/Office SDK: `proposal_id → suggestion_ids[]` (одно бизнес-предложение `P` может визуализироваться несколькими suggestions; принятие `P` идёт через доверенный handler `ResolveProposal`, а не через клиентскую кнопку).
- **Три обязательные чистые проекции** (без мутации live editor):
  1. `original` — принятый документ (без pending suggestions). **Источник по умолчанию для `office.read` в AI SDK.**
  2. `proposed(selectedIds)` — документ с гипотетическим принятием выбранных предложений.
  3. `review` — визуальная разметка с decorations/marks и метаданными.

### 3.2. Клиентский перехват (`@tiptap/core >= 3.34.0`), IME и вложенность
- **Перехват через официальный хук `dispatchTransaction({ transaction, next })`** (введён в `@tiptap/core@3.27.0`; требуется минимум **`>= 3.34.0`** из-за CVE-2026-104847). Использование `filterTransaction` или `appendTransaction` запрещено (в `appendTransaction` неотслеженная правка успевает пройти через `y-prosemirror`, уничтожая `Y.RelativePosition` комментариев).
- **Bypass-метаключи:** не переписывать транзакцию, если `!transaction.docChanged`, review выключен, либо присутствуют `transaction.getMeta(ySyncPluginKey)`, `transaction.getMeta('y-undo')`, `transaction.getMeta('history')` или `'vaulter:untracked'` (учитывать регрессию прилипания `addToHistory: false` в `@tiptap/y-tiptap@3.0.10`).
- **Буферизация IME (`view.composing === true`):** на `compositionstart` сохранять `(preComposeDoc, preComposeSelection)`; во время композиции пропускать сырой ввод в локальный `EditorView` без отправки в Yjs; в первом кадре после `compositionend` вычислить чистую дельту `preComposeDoc → state.doc`, откатить сырой ввод и одним атомарным dispatch с `'vaulter:untracked'` применить финальную suggestion-транзакцию.
- **Обратимые виды операций:**
  - Фаза `8.2` (текст): `insert`, `delete` (удерживает удалённый текст до решения), `replace` (две половины связаны единым `group_id`; общий префикс/суффикс можно оставлять обычным текстом).
  - Фаза `8.3` (структура): `mark_change` (хранит точные `markName`/`markAttrs` и направление `added`/`removed`), `attr_change` (хранит `oldValue`/`newValue`; возврат к `oldValue` снимает предложение), `block_split` (**различать `structural` — склеить через `tr.join` при reject — и `insertion` — удалить пустой блок при reject**), `block_join`, `list_change`, `table_change` (текстовые таблицы).
- **Ограничение live collab:** `nodeTypeChangeTypes` в v1 пуст (`[]`), так как конкурентная смена wrapper-типа узла в Yjs не поддерживается без отдельной спецификации.
- **Группировка, пересечения и вложенный `parentId`:** группировать соседние правки строго по **смежности (adjacency), автору и типу** (без хрупкого `suggestionGroupingTimeout`). Для *частично пересекающихся* предложений v1 сохраняет второе в состоянии `open` с явным конфликтом и исходным автором; для *строго вложенных* правок (`parentId: A`, когда Автор Б правит внутри вставки Автора А) reject внутренней Б откатывает только Б, а reject внешней вставки А переводит дочерние в `superseded` и удаляет каскадно.
- **Атомарный accept/reject:** заново находит актуальные ranges, проверяет права, поколение и CAS, и фиксирует изменение через `AtomicDocumentMutation` (snapshot) или `CommandJournal` (strict journal, 3.5) с выдачей `receipt_id`.

---

## 4. Фазы 10.1 и 10.2: DOCX OOXML и серверный обмен

### 4.1. Единый версионированный mapping (`@vaulter/editor-pro-docx`)
- Пайплайн: `Tiptap JSON / Review ↔ Intermediate Model (versioned) ↔ OOXML (ZIP/XML)` на едином изоморфном TypeScript-ядре (браузерный Web Worker + Node 26).
- **Утверждённый OSS-стек (ноябрь 2026):** **`docx@9.16.0`** (MIT, генерация/патчинг) + **`fflate@0.8.3`** (MIT, браузерный/изоморфный ZIP) + **`yauzl@3.4.0`** (MIT, серверный Node ZIP с проверкой непересечения байтовых диапазонов) + **`saxes@6.0.0`** (ISC) / **`fast-xml-parser >= 5.3.5`** (MIT, строго `processEntities: false`, закрывает GHSA-m7jm-9gc2-mpf2). `mammoth`, `docx-preview`, `unzipper` и Rust/WASM-дубликаты запрещены.
- **Связывание комментариев и `paraId` (совместимость Word ↔ LibreOffice ↔ Google Docs):** MS Word связывает `word/comments.xml` с `commentsExtended.xml` (`w15:commentEx`, `w15:done="1"`) и `commentsIds.xml` (`w16cid`) по 8-символьному hex `w14:paraId` **последнего `<w:p>`** внутри комментария. Все `paraId`/`durableId` генерировать строго в **`00000001..7FFFFFFF`** (старший бит = 0, иначе Word требует ремонта файла). При импорте без `commentsExtended.xml` (LibreOffice) откатываться к группировке по координатам `commentRangeStart`/`End`; при экспорте всегда писать полную тройку XML-частей + `document.xml.rels`. Внутри `<w:del>` писать `<w:delText>`, для краевых/кратных пробелов обязательно `xml:space="preserve"`, `block_join` кодировать пустым `<w:del/>` в `<w:pPr><w:rPr>`.
- **Реестр единиц и стилей:** `15 dxa/px`, `20 dxa/pt`, `9525 EMU/px` (всегда `Math.round()` до целого!), `240` при `w:lineRule="auto"` (дефолт при отсутствии атрибута!), `50 pct/%` (принимать `"5000"` и `"100%"`, писать `"5000"`), шрифт `w:sz` в `1/2 pt` (`24 = 12 pt`), рамки `w:bdr/@w:sz` в `1/8 pt` (`4 = 0.5 pt`, `2..96`). Каскад `styles.xml` (`docDefaults → tblStyle → pStyle(basedOn <= 16) → rStyle → numPr → inline`). `null` означает наследование, а не `0`.
- **Отчёт потерь:** `{profile, source_marker, diagnostics: [{code, severity, feature, location, action}]}`. Обязательное свойство со статусом `error` блокирует публикацию выбранного профиля.
- **Локальный экспорт (10.1):** выполняется полностью в браузерном Web Worker из уже разрешённого подтверждённого snapshot без сетевых запросов (`fetch trap = 0`).

### 4.2. Корпус испытаний D1–D4
| Корпус | Обязательно сохраняется при roundtrip | Допустимые потери (в diagnostics) |
| --- | --- | --- |
| **D1** (body/table) | Порядок строк/ячеек, `tblGrid`, `gridSpan`/`vMerge` (2×2 merge), header row, widths/единицы, bold/link с `href` внутри ячеек | Авто-layout/темы; потеря ячейки или merge блокирует профиль |
| **D2** (sections) | Две секции, distinct `default`/`first`/`even` headers/footers, наследование footer, rels, явные `pageBreak` | Пиксельная пагинация, подстановка шрифтов, динамические поля |
| **D3** (discussions) | Range comments (`comments.xml`, `commentsExtended.xml`, `commentsIds.xml`), replies, resolve, detached и legacy-профиль | Flattening в legacy-профиле, потеря reply при сохранении в LibreOffice (фиксируется в отчёте) |
| **D4** (revisions) | Текстовые `w:ins`/`w:del`/`w:delText`, связь replacement-группы (через versioned metadata), авторство как provenance | Native formatting revisions (`w:rPrChange`) помечаются в loss report, не превращаются молча в принятый стиль |

### 4.3. Серверный обмен (`axiomasoft/vaulter-editor-pro-exchange`, 10.2)
- Маршруты:
  - `POST /documents/{id}/exchange-jobs` — регистрирует `import` (по `source_blob_id` из Media) или `export` (по `source_revision_id` / `source_generation` + `source_position`) с `request_key` и `request_fingerprint`.
  - `GET /documents/{id}/exchange-jobs/{job}` — статус, отчёт потерь, preview.
  - `GET /documents/{id}/exchange-jobs/{job}/file` — скачивание `output_blob_id` с повторной проверкой View/export/history.
  - `POST /documents/{id}/exchange-jobs/{job}/apply` — явное применение импорта через `AtomicDocumentMutation` / `CommandJournal` с отдельным `apply_command_id` и CAS текущей головы.
- **Безопасность парсера:** проверка лимитов распакованного размера, числа ZIP entries, глубины XML и полное отключение XXE / внешних relationships **до** аллокации полной модели.
- **Устойчивость к сбоям:** worker с lease пишет immutable blob в Media, затем фиксирует ссылку в `v_document_exchange_jobs`; при kill между записью Media и SQL commit orphan убирает GC, а повтор завершает один job.

---

## 5. Итоговая диспозиция каталога закрытых редакторских модулей

Все 93 записи каталога, разобранные до реализации, распределены без «белых пятен»:

| Группа Tiptap Pro | Решение для экосистемы Vaulter | Где закреплено |
| --- | --- | --- |
| Cloud Provider, Document REST, JWT Auth, Webhooks | Собственная реализация (`vaulter` + `vaulter-collaboration`) | Фазы `01`, `02`, `03` |
| Comments, Snapshots, Compare Snapshots, Compare, Tracked Changes | Собственная реализация в `vaulter-editor-pro` | Фаза `08` (`8.1`–`8.3`) |
| Server AI Toolkit, Basic AI, Client AI Toolkit | Собственный Office SDK v1 + возобновляемый AI-чат SSE в `vaulter-ai` | Фазы `03`, `05`, `07`, `09` |
| ConvertKit, DOCX Import/Export | Собственный OOXML pipeline в `vaulter-editor-pro` | Фаза `10` (`10.1`–`10.2`) |
| Markdown Import/Export | Встроенный тип `vaulter/markdown` + OSS `@tiptap/markdown` | Фаза `01` (`vaulter`) |
| OSS Utilities (`UniqueID`, `TableOfContents`, `DragHandle`, `FileHandler`, `FindAndReplace`, `TextStyleKit`, `Collaboration`, `CollaborationCaret`, `Emoji`, `Mathematics`, `Details`, `InvisibleCharacters`) | **Переиспользование OSS `@tiptap/*`** (не переписывать с нуля; при необходимости проверять schema parity и привязку к `blockId`/Media ACL) | `vaulter` / `vaulter-collaboration` |
| **Отложено в P2 (вне фаз `00–10`, отдельные будущие расширения):** Pages / PageKit / PagesTableKit / PageBreak, Composable Docs (Content Protection / Slots / Variables), Diagrams, Whiteboard, legacy DOC / PDF / ODT / EPUB export, Paste Handler | Явно исключены из текущего объёма `00–10`; подключаются как независимые расширения по отдельному продуктовому заказу и никогда не попадают в базовый `vaulter` | Backlog P2 |
