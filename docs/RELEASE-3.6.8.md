# v3.6.8

- Media folders respect WordPress native static upload grids, absolute media modal wrappers, attachment-details right insets and compact expandable drawers. Resources now load only in admin screens using media-views; no frontend observer or unrelated dashboard script.
- Shared presentation follows the supplied HTML's violet/indigo palette, subtle cards and light settings header. No external fonts, heavy blur layer or new frontend styling.
- Cursor settings retain all three saved color keys. A contained preview follows pointer coordinates relative to its own box and updates immediately without saving. It never replaces the whole admin cursor.
- Taxonomy ordering displays the complete hierarchy with search retaining ancestor paths. Filtering disables movement to avoid saving a partial subset. Drag and keyboard buttons reorder siblings only. Explicit save sends only changed groups sequentially; failed groups remain dirty and retryable. Successful groups are not resent. The existing nonce and term-management capability checks remain mandatory. Post ordering is unchanged.
- Classic and block editors share inert local SEO analysis. Existing checks now explain their purpose; structural reading and answer-first suggestions are advisory, not ranking or indexing guarantees. No remote analysis, key collection, automatic indexing service, forced FAQ schema or keyword-density score.

## Validation

PHP policy fixtures cover nonce/capabilities, no writes for invalid sibling groups, hierarchy and scoped media output. Browser tests use actual WordPress core CSS and jQuery UI across modal/upload, legacy/wrapped media layouts and desktop/tablet/mobile, plus detail-sidebar preservation, expanded compact drawers and tree persistence/retry. Existing design tests cover four viewport widths and live cursor preview. SEO fixtures cover explanations, heading hierarchy, long paragraphs and question/answer detection.
