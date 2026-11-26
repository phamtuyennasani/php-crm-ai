# Quản lý bài gốc và các phiên bản ngôn ngữ

Trong CRM, mở **Yêu cầu viết → Nhóm theo bài gốc**. Mỗi hàng là một bài của một website, có mã bài, ngôn ngữ gốc, các ngôn ngữ đã viết và trạng thái. Mở rộng hàng để xem các phiên bản; chọn một ngôn ngữ để mở lần viết gần nhất. **Xem toàn bộ lịch sử** mở danh sách yêu cầu đã lọc theo nhóm.

CRM gom nhóm trước khi phân trang. Yêu cầu của nhiều website có thể xen kẽ hoặc đến không theo thứ tự mà không tách các bản dịch sang trang/nhóm khác. Mã nội bộ giống nhau như `news:123` ở hai website luôn là hai nhóm riêng. Bộ lọc khớp một lần viết trong nhóm; nhóm trả đủ các phiên bản liên quan. Bản xem trước nhóm giới hạn 50 dòng, lịch sử đầy đủ vẫn phân trang theo yêu cầu.

## Các mã định danh

| Trường | Ý nghĩa |
| --- | --- |
| `article_ref` | Mã bài ổn định do website quản lý, ví dụ `news:123` hoặc một UUID bản nháp; dùng chung cho mọi ngôn ngữ và lần viết của bài đó. |
| `article_group_id` | Mã nhóm CRM trả về; khác với mã từng job. Backend xác định trong transaction, theo đúng website và khách hàng đã xác thực. |
| `original_locale` | Ngôn ngữ gốc của nhóm; không đổi ngầm khi website đổi ngôn ngữ mặc định. |
| `original_job_id` | Lần viết gốc AI đầu tiên của nhóm, nếu có; bản gốc nhập sẵn trên website có thể chưa có job CRM. |
| `translation_of_job_id` | Đúng phiên bản AI nguồn được dùng cho bản dịch; có thể khác lần gốc đầu tiên khi người dùng đã viết lại bản gốc. |
| `source_article_id` | Quan hệ viết lại hiện có, được giữ riêng với quan hệ chuyển ngữ. |

Mỗi lần viết vẫn có `job_id` riêng, cơ chế idempotency và quota riêng. Gom nhóm phục vụ quản lý, không gộp hoặc trừ thêm lượt viết. Retry của cùng yêu cầu giữ nguyên nhóm; viết lại và retry thủ công kế thừa nhóm của yêu cầu nguồn.

CRM kiểm tra lần viết nguồn thuộc đúng website, khách hàng, đã hoàn tất và đúng ngôn ngữ gốc. Gắn chéo website hoặc dùng mã bài khác với nhóm nguồn bị từ chối trước khi giữ lượt mới. Không được gửi `article_group_id` để tự chọn nhóm tùy ý. Khi bài gốc còn được bản dịch tham chiếu, CRM chặn xóa bản gốc để giữ liên kết.

## Cấu hình widget

```js
AIConfig.articleRef = 'news:123';
```

Không thêm mã ngôn ngữ vào `articleRef`. Không dùng tiêu đề, từ khóa, số thứ tự lần gọi hoặc thời gian làm khóa bài. Website nên lưu một mã ổn định từ lúc bản nháp được tạo và trả lại mã đó khi mở lại bài.

Nếu có mã lần viết nguồn đã chấp nhận, truyền qua `AIConfig.sourceJobId`. Widget tự nhớ lần nguồn vừa chấp nhận khi đang mở form, và gửi thành `translation_of_job_id` khi tạo bản dịch. Snapshot `translation_source` luôn lấy từ nội dung mới nhất trên form/editor, nên vẫn giữ các chỉnh sửa sau khi nhận bài từ AI. Tab **Bản gốc dùng để dịch** trong chi tiết CRM hiển thị snapshot này, bên cạnh liên kết tới phiên bản AI nguồn.

Khi chưa có `articleRef`, widget tạo một `draft:<UUID>` trên form, dùng lại trong cùng phiên làm việc. Sau khi chấp nhận một kết quả, form phát sự kiện `crm-ai:article-group` với `detail` gồm `article_ref`, `article_group_id`, `source_job_id`. Ứng dụng cần lưu `article_ref` và `source_job_id` cùng bản ghi bài viết khi người dùng bấm lưu, rồi truyền lại khi mở bài ở lần sau. Nếu không lưu, mã nháp tự sinh chỉ tồn tại trên form đang mở.

## Laravel

```blade
<x-crm-ai::writer
    form="#article-form"
    :fields="$aiFields"
    :languages="$aiLanguages"
    :article-ref="$articleReference"
    :source-job-id="$savedSourceJobId"
/>
```

`$articleReference` và `$savedSourceJobId` lấy từ metadata bài của ứng dụng, không từ người dùng ngoài trang biên tập. Có thể nghe sự kiện trên form để cập nhật các input metadata, rồi xác thực và lưu cùng bài trong controller.

BaseCodeAW đã tích hợp: mã nháp, ngôn ngữ gốc và lần AI nguồn được lưu vào các khóa `_crm_ai_ref`, `_crm_ai_source_locale`, `_crm_ai_source_job_id` trong JSON `news.options`. Mã vẫn giữ nguyên sau khi thêm bài, chuyển trang, sửa lại hoặc đổi trình duyệt; không cần cột SQL mới. Bài cũ chưa có metadata dùng `news:<id>`. Khi tạo bản sao mới cần tạo mã nháp mới, không sao chép mã của bài cũ.

## PHP thuần

Truyền `article_ref` và `source_job_id` trong options của `Widget::render()`. Khi lưu bài, lưu hai giá trị nhận từ sự kiện vào các cột/metadata phù hợp của website, và truyền lại khi render editor. Backend vẫn phải kiểm tra đăng nhập, quyền sửa bài và CSRF trước khi lưu metadata.

## Dữ liệu cũ

Yêu cầu không có khóa bài được giữ riêng, hiển thị là dữ liệu cũ chưa liên kết đa ngôn ngữ. CRM không tự ghép theo từ khóa, tiêu đề hoặc nội dung giống nhau, tránh nối nhầm bài và website. Các quan hệ viết lại đã có vẫn được giữ để đối chiếu.
