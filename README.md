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
- `functions.php`: nạp trang, trang cài đặt, lưu đơn đăng ký và email.
- `templates/`: nội dung hai trang, tạo ra từ `design/`.
- `design/`: bản thiết kế gốc. Sửa ở đây rồi chạy `python3 tools/build.py`.
- `assets/js/runtime.js`: phần chạy tương tác trên trang.
- `assets/img/`: ảnh.

## Đơn đăng ký và email

- Đơn mới nằm ở mục **Đơn đăng ký** trong trang quản trị, và được gửi email tới người quản trị.
- Email xin nhận bài học nằm ở mục **Email nhận bài học**.
- Thanh toán là chuyển khoản hoặc PayPal, xác nhận bằng tay. Theme không tự thu tiền.
