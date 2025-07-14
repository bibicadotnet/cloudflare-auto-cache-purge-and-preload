# Cloudflare Auto Cache Purge & Preload v1.2.2

Plugin tự động xóa và preload cache Cloudflare khi có thay đổi trên WordPress.

## Tính năng
- **Tự động xóa cache** khi bài viết, trang, bình luận, danh mục, thẻ thay đổi.
- **Preload cache sau khi xóa** để cải thiện tốc độ tải.
- **Nút "Clear Cache" & "Preload Cache"** trên admin bar.
- **Preload toàn bộ trang hàng ngày**.
- **Cấu hình dễ dàng** qua giao diện web.
- **Ghi log quá trình xóa & preload cache**.

## Cài đặt
1. **Cài plugin** như WordPress plugin thông thường.
2. **Cấu hình tại** `Admin Dashboard → Tools → Cloudflare Auto Cache Purge & Preload`.
3. **Điền thông tin**:
   - Cloudflare API Email, API Key, Zone ID
   - Sitemap URL (mặc định `/sitemap.xml`)
   - URLs tùy chỉnh cần xóa cache
   - Bật/tắt logging & auto preload

## Tối ưu hiệu năng
- **Chạy WP-Cron mỗi 1-5 giây** bằng Systemd Timer, WP-CLI hoặc bash script.
- **Thêm vào `wp-config.php`**:
  ```php
  define('WP_CRON_LOCK_TIMEOUT', 1);
  ```
- **Dùng WP Crontrol** để tạo cron schedule 1 giây.
- **Tối ưu `action_scheduler_run_queue` về 1 giây**.

## Xóa log để giảm tải
```php
function cleanup_action_scheduler_logs() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_logs");
    $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('complete', 'failed')");
}
add_action('init', 'cleanup_action_scheduler_logs');
add_filter('action_scheduler_store_logs', '__return_false');
```

## Hiệu suất
Tùy chỉnh tùy thuộc vào số core CPU và tình trạng đang sử dụng 

Trên VPS 4 core Oracle
- **Preload 600 trang mới: ~42 giây**.
- **Preload 600 trang đã có cache: ~5 giây**.
- **Xóa & preload bài viết mới: ~10 giây**.

Trên VPS 1 core cấu hình thấp nhất của UpCloud
- **Preload 600 trang mới: ~120 giây**.
- .........

## Lỗi & Góp ý
Mở issue hoặc comment để được hỗ trợ.

