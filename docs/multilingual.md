# Cấu hình ngôn ngữ cho AI Writer

Website khai báo ngôn ngữ nội dung trong `AIConfig.languages`. Ngôn ngữ gốc lấy từ cấu hình website, không từ ngôn ngữ trình duyệt hoặc tên miền. Các mã dùng BCP 47 như `en`, `vi`, `en-US`, `ja`, `zh-Hant`, `ar`. Tối đa 10 ngôn ngữ đích; không trùng bản gốc hoặc trùng nhau sau chuẩn hóa.

```js
const AIConfig = {
  mount: '#ai-tools',
  form: '#article-form',
  languages: { source: 'en', targets: ['vi', 'ja'] },
  fields: { Title: '[name="name[en]"]', Content: '[name="content[en]"]' },
  fieldsByLocale: {
    vi: { Title: '[name="name[vi]"]', Content: '[name="content[vi]"]' },
    ja: { Title: '[name="name[ja]"]', Content: '[name="content[ja]"]' },
  },
};
window.AIWriter.mount(AIConfig);
```

`fields` là mapping bản gốc; có thể cung cấp lại bản gốc trong `fieldsByLocale`. Mỗi ngôn ngữ đích cần mapping riêng. Hai ngôn ngữ không được trỏ vào cùng phần tử form. Có thể map thêm `Slug`, `Desc`, `Description`, `Keywords`, `FocusKeyword`. Với rich editor, truyền `{selector, setValue, getValue}`; `getValue(element)` trả chuỗi nội dung mới nhất từ editor. Khi thiếu `getValue`, widget đọc giá trị input/textarea.

## Quy trình

1. Chọn ngôn ngữ gốc và viết bài.
2. Chấp nhận, kiểm tra và chỉnh nội dung trong form website.
3. Mở widget, chọn một ngôn ngữ đích và bấm **Tạo bản dịch**. Bản gốc cần có tiêu đề và nội dung; nội dung có sẵn trước khi dùng AI cũng được hỗ trợ.
4. Widget gửi snapshot bản gốc hiện tại. CRM chuyển ngữ tiêu đề, nội dung và metadata, giữ dữ kiện, tên riêng, HTML và URL. Chuyển ngữ không chạy lại nghiên cứu/dàn ý hoặc tự viết lại để ép số từ và điểm SEO.
5. Xem trước và chấp nhận để điền đúng ngôn ngữ đích. Nếu bản gốc đã đổi trong lúc dịch, cần tạo bản dịch mới. Khi đích có nội dung, widget hỏi trước khi thay thế.

Mỗi bản gốc/bản dịch là một job riêng, tính một lượt nếu dùng gói dịch vụ. Retry mạng giữ nguyên body và Idempotency-Key để nhận lại cùng job. Một bản dịch lỗi không làm thay đổi các ngôn ngữ đã có trên form. Sau khi chấp nhận vẫn lưu bài qua chức năng hiện có của website.

Giao diện widget hiện dùng tiếng Việt; ngôn ngữ giao diện độc lập với ngôn ngữ nội dung. Preview hỗ trợ chiều RTL cho các locale phổ biến. CRM giữ slug Unicode, nhưng adapter website vẫn có thể áp dụng quy tắc slug của CMS. Báo cáo SEO dùng checklist chung của CRM, không phải bộ tiêu chuẩn riêng cho mọi thị trường. Với bản dịch, có thể nhập từ khóa đích để báo cáo phù hợp hơn.

## Dữ liệu gửi CRM

Mỗi lần tạo job gửi `languages`, `locale` (ngôn ngữ kết quả), và khi chuyển ngữ gửi `translation_source`:

```json
{
  "languages": { "source": "en", "targets": ["vi", "ja"] },
  "locale": "vi",
  "translation_source": {
    "Title": "Approved English title",
    "Content": "<p>The edited and reviewed English text.</p>"
  }
}
```

Các trường viết bài khác vẫn giữ nguyên. Snapshot hỗ trợ thêm `Desc`, `Description`, `Keywords`; `Content` tối đa 120.000 đơn vị chuỗi JavaScript, yêu cầu JSON tối đa 512 KiB qua widget. Chữ ký máy chủ ràng buộc toàn bộ cấu hình ngôn ngữ và snapshot. CRM kiểm tra mã locale, tính duy nhất, ngôn ngữ đích thuộc danh sách và bản gốc bắt buộc khi dịch. Không khai báo `languages` thì giữ hành vi tiếng Việt của tích hợp cũ.

## Laravel

```blade
<x-crm-ai::writer
    form="#article-form"
    :fields="['Title' => '#title-en', 'Content' => '#body-en']"
    :languages="['source' => 'en', 'targets' => ['vi']]"
    :fields-by-locale="['vi' => ['Title' => '#title-vi', 'Content' => '#body-vi']]"
/>
```

Có thể đặt `languages` trong `config/crm-ai.php` và chỉ truyền mapping theo từng form. BaseCodeAW tự lấy source từ `LanguageService::default()` và targets từ danh sách ngôn ngữ còn lại; dùng `getData()` cho CKEditor. Các trường SEO/slug dùng chung giữa các ngôn ngữ chỉ map vào bản gốc.

## PHP thuần

```php
echo $widget->render([
    'authorize_url' => '/admin/crm-authorize.php',
    'form' => '#article-form',
    'languages' => ['source' => 'en', 'targets' => ['vi']],
    'fields' => ['Title' => '#title-en', 'Content' => '#body-en'],
    'fields_by_locale' => ['vi' => ['Title' => '#title-vi', 'Content' => '#body-vi']],
], $csrf);
```

Nâng cả package và script CRM khi bật đa ngôn ngữ. Package từ 1.1.0 tăng giới hạn endpoint cấp chữ ký để nhận snapshot nội dung; giữ cơ chế xác thực, quyền biên tập và CSRF của website.
