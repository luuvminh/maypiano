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
- `inc/emails.php`: email gửi khách và gửi chủ site.
- `inc/settings.php`: trang **Cài đặt > May Piano**.
- `templates/`: nội dung hai trang, tạo ra từ `design/`.
- `design/`: bản thiết kế gốc. Sửa ở đây rồi chạy `python3 tools/build.py`.
- `assets/js/`: `runtime.js` chạy trang, `vietqr.js` tạo và đọc mã chuyển khoản, `qrcode.js` (MIT) vẽ mã QR, `jsqr.js` (Apache-2.0) đọc ảnh mã QR ở trang cài đặt.

## Quy trình thanh toán

1. Khách chọn khóa, xem giỏ, điền họ tên và email, chọn cách trả.
2. Site tạo một đơn trong **WooCommerce > Orders**, trạng thái On hold. Giá do máy chủ tính, không lấy từ trình duyệt.
3. Khách ở Việt Nam thấy mã QR Techcombank có sẵn số tiền và nội dung `MP<số đơn>`. Khách ở nước ngoài thấy nút PayPal.
4. Khách bấm "Mình đã chuyển khoản". Chủ site nhận email. Cột **May Piano** trong danh sách đơn ghi "KHÁCH BÁO ĐÃ TRẢ".
5. Chủ site thấy tiền về thì đổi đơn sang **Completed**. Site tự tạo tài khoản học, mở khóa trong Tutor LMS và gửi email cho khách. Trang của khách tự chuyển sang "Khóa học của bạn đã mở".
6. Hoàn tiền: đổi đơn sang Refunded, Tutor LMS tự đóng khóa của đơn đó.

Nếu site không mở được khóa (chưa nối khóa, khóa còn là bản nháp), đơn được ghi chú và chủ site nhận email "CẦN GHI DANH TAY".

## Thanh toán bằng thẻ

Cài đặt > May Piano > Thanh toán bằng thẻ có ba chế độ:

- **Tắt**.
- **Chạy thử** (mặc định): chỉ quản trị viên đang đăng nhập thấy. Có hai nút "Thẻ trả thành công" và "Thẻ bị từ chối". Không có ô nhập số thẻ, không có tiền thật. Đơn được đánh dấu CHẠY THỬ.
- **Thật**: sau khi cài cổng Stripe cho WooCommerce, khách được chuyển sang trang trả tiền của WooCommerce cho đúng đơn đó, trả xong quay về `/dang-ky/`.

## Email nhận bài học

Email xin nhận bài học nằm ở mục **Email nhận bài học**.
