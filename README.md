# May Piano

Theme WordPress cho trang chủ và trang đăng ký khóa học của May Piano.

## Cách đưa lên WordPress.com

1. Vào trang quản trị site trên WordPress.com, mục **Deployments** (GitHub Deployments).
2. Kết nối repo `luuvminh/maypiano`, nhánh `main`.
3. Thư mục đích: `/wp-content/themes/maypiano`.
4. Bấm deploy, rồi vào **Giao diện > Theme** và kích hoạt theme **May Piano**.
5. Vào **Cài đặt > May Piano** để điền số tài khoản, mã QR, giá USD và thời gian kích hoạt.

## Trong repo có gì

- `front-page.php`: trang chủ.
- `page-dang-ky.php`: trang đăng ký và thanh toán, địa chỉ `/dang-ky/`. Trang này được tạo tự động khi bật theme.
- `functions.php`: nạp trang và các phần trong `inc/`.
- `inc/catalog.php`: danh sách khóa, giá theo vùng, nối với sản phẩm WooCommerce và khóa Tutor LMS.
- `inc/orders.php`: đặt đơn, báo đã trả, xác nhận, tạo tài khoản học, mở khóa học.
- `inc/approve.php`: trang duyệt đơn bằng một nút cho chủ site, mở từ email.
- `inc/account.php`: trang đăng nhập, quên mật khẩu và đặt mật khẩu cho học viên, giao diện May Piano, tiếng Việt (`/?tk=dang-nhap`, `/?tk=quen-mat-khau`, `/?tk=dat-mat-khau`). Đường dẫn đặt mật khẩu kiểu WordPress cũ tự chuyển về đây.
- `inc/emails.php`: email gửi khách và gửi chủ site. Gồm cả email hoàn tiền; email hoàn tiền có sẵn của WooCommerce được tắt. Người gửi lấy theo WooCommerce > Settings > Emails.
- `inc/mailpoet.php`: tiếng Việt cho MailPoet (email xác nhận đăng ký nhận bài học, trang báo đăng ký thành công, tên người gửi). Bảng dịch nằm ở `languages/mailpoet-vi.json`.
- `inc/videos.php`: ba video dài mới nhất của kênh YouTube May Piano cho trang chủ (không lấy Shorts). Site đọc nguồn tin của kênh, nhớ kết quả một giờ. Đọc không được thì dùng lại danh sách lần trước.
- `inc/settings.php`: trang **Cài đặt > May Piano**.
- `inc/chrome.php`: thanh menu, chân trang và giao diện chung cho mọi trang ngoài trang chủ và trang đăng ký (trang khóa học, khu học viên, tài khoản, trả tiền bằng thẻ). Cũng chuyển trang cửa hàng, sản phẩm, giỏ hàng và thanh toán của WooCommerce về `/dang-ky/`, để chỉ có một đường mua.
- `inc/i18n.php` và `languages/tutor-vi.json`: tiếng Việt cho Tutor LMS (plugin không có sẵn tiếng Việt). Muốn sửa một câu thì sửa trong file JSON.
- `inc/fx.php`: theo dõi tỷ giá. Mỗi tuần đọc tỷ giá Vietcombank; lệch từ 7% so với lúc đặt giá thì gửi email hỏi chủ site có đổi giá AUD, USD không. Giá chỉ đổi khi chủ site bấm áp dụng ở **Cài đặt > May Piano > Tỷ giá**.
- `inc/curriculum.php` và `data/`: dựng các phần và bài học của một khóa trong Tutor LMS từ file danh sách (`data/dem-hat-piano.json` là khóa ở địa chỉ `dem-hat-piano`). Sửa file rồi đưa lên là site tự cập nhật; không tạo trùng, không xóa bài nào. Kết quả lần đọc gần nhất hiện ở đầu trang **Cài đặt > May Piano**.
- `inc/bunny.php`: video bài học trên Bunny Stream. Ba khóa Bunny điền ở **Cài đặt > May Piano > Video bài học**, không nằm trong repo. Video trên Bunny đặt tên `<video_prefix> <số bài hai chữ số> · ...` (ví dụ `Đệm Hát 07 · ...`) thì site tự gắn vào đúng bài mỗi lần mở trang cài đặt. Link xem được ký mới mỗi lần, hết hạn sau 6 giờ, chỉ cho học viên đã ghi danh, người dạy, hoặc bài xem thử.
- `single-courses.php`, `inc/course-page.php`, `assets/css/course.css`: trang của một khóa học, theo giao diện May Piano, thay cho trang của Tutor LMS. Các phần và bài lấy từ Tutor LMS; lời giới thiệu, ba điều học được và ảnh lấy từ file của khóa trong `data/` (`intro`, `gets`, `photo`). Nút mua dẫn về `/dang-ky/` với đúng khóa đó; học viên đã ghi danh thấy nút Vào học và bấm được từng bài.
- `assets/css/lesson.css`: giao diện May Piano cho trang học bài của Tutor LMS (cột danh sách bài, khung video, nút Trước và Tiếp). Trang này luôn dùng nền sáng, kể cả khi máy học viên đặt chế độ tối.
- `inc/enrol-by-hand.php`: mục **Ghi danh tay** ở Cài đặt > May Piano. Chủ site mở một khóa cho một người mà không cần đơn hàng; site tạo tài khoản nếu chưa có và hiện đường dẫn đặt mật khẩu để gửi cho họ.
- `inc/qna.php`: Hỏi đáp trong khóa học. Tên hiện bên cạnh câu hỏi là họ tên của học viên, không phải tên đăng nhập hay email. Mỗi câu hỏi có một mục riêng tư trong **Câu hỏi chờ trả lời** (`mp_hoi`): `pending` là chờ trả lời, `draft` là đã có nháp trong ô tóm tắt (excerpt) chờ duyệt, `private` là đã trả lời. Chuyển một mục sang `private` khi ô tóm tắt có chữ thì site đăng chữ đó dưới câu hỏi, tên người trả lời là Trợ lý May Piano. Mục không bao giờ công khai. Câu hỏi ghi lại bài học mà học viên đang xem lúc bấm Hỏi đáp. Mở Hỏi đáp từ một bài thì trang chỉ hiện câu hỏi của bài đó, có nút **Xem tất cả** để xem mọi câu hỏi của khóa (khi đó mỗi câu ghi tên bài). Trang vẫn là trang của Tutor LMS, nạp qua `inc/qna-page.php`; theme chỉ thêm điều kiện lọc và thanh chọn. Câu hỏi không rõ bài chỉ hiện ở Xem tất cả.
- `inc/teacher.php`: tài khoản người dạy tên Mây, lấy email ở Cài đặt > May Piano; mọi khóa, phần và bài thuộc về tài khoản này.
- `assets/css/app.css`: design system cho các trang đó. Màu, font và kiểu nút lấy từ `design/TrangChu.dc.html`.
- `woocommerce.php`: khuôn cho các trang WooCommerce còn dùng.
- `templates/`: nội dung hai trang, tạo ra từ `design/`.
- `design/`: bản thiết kế gốc. Sửa ở đây rồi chạy `python3 tools/build.py`.
- `assets/audio/`: tiếng đàn grand piano cho bàn phím ở trang chủ. Nguồn: Salamander Grand Piano V3 của Alexander Holm, giấy phép CC BY 3.0 (phải ghi tên tác giả, đã ghi ở chân trang chủ).
- `assets/audio/nen/`: nhạc nền trang chủ. 14 đoạn mở đầu (90 giây mỗi đoạn) do Mây đàn, cắt từ video "Những Bản Tình Ca Mùa Thu Nhẹ Nhàng". Bài "Ánh Trăng Nói Hộ Lòng Tôi" (`09.mp3`) đang tạm bỏ vì âm thanh lỗi (`SKIP`). Mỗi lượt vào trang phát ngẫu nhiên một đoạn, âm lượng nhỏ; hết đoạn thì sang đoạn khác. Tên bài theo thứ tự file nằm ở `SONGS` trong `design/TrangChu.dc.html`.
- `assets/js/`: `runtime.js` chạy trang, `vietqr.js` tạo và đọc mã chuyển khoản, `qrcode.js` (MIT) vẽ mã QR, `jsqr.js` (Apache-2.0) đọc ảnh mã QR ở trang cài đặt.

## Quy trình thanh toán

1. Khách chọn khóa, xem giỏ, điền họ tên và email, chọn cách trả.
2. Site tạo một đơn trong **WooCommerce > Orders**, trạng thái On hold. Giá do máy chủ tính, không lấy từ trình duyệt.
3. Khách ở Việt Nam thấy mã QR Techcombank có sẵn số tiền và nội dung `MP<số đơn>`. Khách ở nước ngoài thấy nút PayPal.
4. Khách bấm "Mình đã chuyển khoản". Chủ site nhận email. Cột **May Piano** trong danh sách đơn ghi "KHÁCH BÁO ĐÃ TRẢ".
5. Chủ site thấy tiền về thì bấm nút **Duyệt đơn này** trong email (mở một trang nhỏ trên điện thoại, không cần đăng nhập, bấm thêm một nút để xác nhận). Đổi đơn sang **Completed** trong WooCommerce cũng được. Site tự tạo tài khoản học, mở khóa trong Tutor LMS và gửi email cho khách. Trang của khách tự chuyển sang "Khóa học của bạn đã mở".
6. Hoàn tiền: đổi đơn sang Refunded, Tutor LMS tự đóng khóa của đơn đó.

Nếu site không mở được khóa (khóa chưa có trong Tutor LMS), đơn được ghi chú và chủ site nhận email "CHƯA MỞ ĐƯỢC KHÓA" có nút **Thử mở khóa học lại**. Site cũng tự thử lại mỗi giờ và mỗi khi một khóa Tutor LMS được lưu; mở được thì tự gửi email cho khách.

## Thanh toán bằng thẻ

Cài đặt > May Piano > Thanh toán bằng thẻ có ba chế độ:

- **Tắt**.
- **Chạy thử** (mặc định): quản trị viên đang đăng nhập thấy. Khách chưa đăng nhập cũng thấy nếu mở đường dẫn chạy thử ghi ở trang cài đặt (`/dang-ky/?thu=<mã>`, nhớ trong 24 giờ). Đừng gửi đường dẫn này ra ngoài. Có hai nút "Thẻ trả thành công" và "Thẻ bị từ chối". Không có ô nhập số thẻ, không có tiền thật. Đơn được đánh dấu CHẠY THỬ.
- **Thật**: sau khi cài cổng Stripe cho WooCommerce, khách được chuyển sang trang trả tiền của WooCommerce cho đúng đơn đó, trả xong quay về `/dang-ky/`.

## Email nhận bài học

Email xin nhận bài học nằm ở mục **Email nhận bài học**.
