<?php
/**
 * Plugin Name: Cloudflare Auto Cache Purge And Preload
 * Description: Tự động xóa và preload cache cho bài viết, trang, danh mục và thẻ sử dụng Cloudflare API và Action Scheduler.
 * Version: 1.2.1
 * Author: bibica
 * Author URI: https://bibica.net
 * Plugin URI: https://bibica.net/cloudflare-auto-cache-purge-and-preload
 * Text Domain: cloudflare-auto-cache-purge-and-preload
 * License: GPL-3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CF_PURGE_LOG', WP_CONTENT_DIR . '/cloudflare_purge_preload.log');

class Cloudflare_Auto_Cache_Purge_And_Preload {
    private $supported_post_types = ['post', 'page'];
    const ACTION_PURGE_URLS = 'cf_purge_urls_action';
    const ACTION_PRELOAD_URLS = 'cf_preload_urls_action';
    const MAX_URLS_PER_BATCH = 30;
    
    public function __construct() {
        // Giảm thời gian sleep khi xử lý các tác vụ Action Scheduler
        add_filter('action_scheduler_async_request_sleep_seconds', function() { 
            return 0.1; // Giảm thời gian sleep xuống 0.1 giây thay vì 5 giây mặc định
        }, 10, 1);

        if (!class_exists('ActionScheduler_Webhook')) {
            require_once('lib/action-scheduler/action-scheduler.php');
        }

        // Khởi tạo settings
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_init', [$this, 'handle_reset_settings']);		
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
		add_action('wp_after_insert_post', [$this, 'handle_save_post'], 10, 4);
        add_action('before_delete_post', [$this, 'handle_before_delete_post']);
        add_action('edited_term', [$this, 'handle_edit_term'], 10, 3);
        add_action('delete_term', [$this, 'handle_delete_term'], 10, 4);
        add_action('wp_trash_post', [$this, 'handle_trash_post']);
        add_action('transition_post_status', [$this, 'handle_status_change'], 10, 3);
		add_action('process_schedule_urls', [$this, 'process_schedule_urls'], 10, 3);
		// Thêm hook xử lý comment
		add_action('transition_comment_status', [$this, 'handle_comment_status_change'], 10, 3);
		add_action('comment_post', [$this, 'handle_new_comment'], 10, 3);
		add_action('edit_comment', [$this, 'handle_comment_edit'], 10, 2);
		add_action('delete_comment', [$this, 'handle_comment_deletion'], 10, 2);

        add_action(self::ACTION_PURGE_URLS, [$this, 'process_purge_urls_batch']);
        add_action(self::ACTION_PRELOAD_URLS, [$this, 'process_preload_urls_batch']);
		
		        // Add new hooks for admin bar and notices
		add_action('admin_bar_menu', [$this, 'add_admin_bar_cache_buttons'], 90);
        add_action('admin_notices', [$this, 'display_admin_notices']);
		        // Đăng ký xử lý admin post
        add_action('admin_post_cloudflare_clear_cache', [$this, 'handle_cloudflare_clear_cache']);
        add_action('admin_post_cloudflare_preload_cache', [$this, 'handle_cloudflare_preload_cache']);

        // Đăng ký action cho preload sitemap
        $this->register_preload_sitemap_action();

		// Đăng ký auto preload sitemap
	#	add_action('update_option_cloudflare_cache_options', [$this, 'schedule_cloudflare_preload_cache'], 10, 2);
		add_action('cloudflare_daily_preload_cache', [$this, 'handle_cloudflare_daily_preload_cache']);
		add_action('update_option_cloudflare_cache_options', [$this, 'update_cloudflare_preload_cron'], 10, 2);
		register_deactivation_hook(__FILE__, [$this, 'deactivate_cloudflare_preload_cache']);
		register_activation_hook(__FILE__, [$this, 'schedule_cloudflare_preload_cache']);
	
        if (defined('WP_CLI') && WP_CLI) {
            $this->register_cli_commands();
        }
    }

    private function register_cli_commands() {
        WP_CLI::add_command('cloudflare purge', function($args, $assoc_args) {
            $urls = get_option('cf_urls_to_purge', []);
            if (empty($urls)) {
                WP_CLI::success('Không có URL nào cần xóa cache.');
                return;
            }
            
            $batches = array_chunk($urls, self::MAX_URLS_PER_BATCH);
            foreach ($batches as $batch) {
                $this->process_purge_urls_batch($batch);
            }
            
            delete_option('cf_urls_to_purge');
            WP_CLI::success('Đã xóa cache cho ' . count($urls) . ' URLs.');
        });

        WP_CLI::add_command('cloudflare preload', function($args, $assoc_args) {
            $urls = get_option('cf_urls_to_preload', []);
            if (empty($urls)) {
                WP_CLI::success('Không có URL nào cần preload.');
                return;
            }

            $batches = array_chunk($urls, self::MAX_URLS_PER_BATCH);
            foreach ($batches as $batch) {
                $this->process_preload_urls_batch($batch);
            }
            
            delete_option('cf_urls_to_preload');
            WP_CLI::success('Đã preload ' . count($urls) . ' URLs.');
        });
    }


   private function log_message($message) {
        try {
            $options = get_option('cloudflare_cache_options');
            if (isset($options['enable_logging']) && $options['enable_logging'] === '1') {
                $log_time = current_time('mysql');
                $log = "[" . $log_time . "] " . $message . PHP_EOL;
                file_put_contents(CF_PURGE_LOG, $log, FILE_APPEND);
            }
        } catch (Exception $e) {
            error_log("Lỗi ghi log Cloudflare Cache: " . $e->getMessage());
        }
    }

    public function handle_status_change($new_status, $old_status, $post) {
        try {
            if ($new_status === $old_status || !in_array($post->post_type, $this->supported_post_types)) {
                return;
            }

            if ($old_status === 'trash' && $new_status === 'publish') {
                $urls = $this->collect_urls_to_purge($post);
                $this->schedule_urls_processing($urls);
            }
        } catch (Exception $e) {
            $this->log_message("Lỗi xử lý thay đổi trạng thái: " . $e->getMessage());
        }
    }
	/**
	 * Xử lý khi trạng thái comment thay đổi
	 */
	public function handle_comment_status_change($new_status, $old_status, $comment) {
		try {
			// Clear cache khi comment được chuyển sang approved từ bất kỳ trạng thái nào khác
			if ($new_status === 'approved' && $old_status !== 'approved') {
				$this->purge_cache_for_comment($comment);
			}
			// Clear cache khi comment được chuyển từ approved sang trạng thái khác
			else if ($old_status === 'approved' && $new_status !== 'approved') {
				$this->purge_cache_for_comment($comment);
			}
		} catch (Exception $e) {
			$this->log_message("Lỗi xử lý thay đổi trạng thái comment: " . $e->getMessage());
		}
	}

	/**
	 * Xử lý khi có comment mới được đăng mà không cần phê duyệt
	 */
	public function handle_new_comment($comment_id, $comment_approved, $commentdata) {
		try {
			// Chỉ xử lý khi comment được tự động phê duyệt
			if ($comment_approved === 1) {
				$comment = get_comment($comment_id);
				$this->purge_cache_for_comment($comment);
			}
		} catch (Exception $e) {
			$this->log_message("Lỗi xử lý comment mới: " . $e->getMessage());
		}
	}
	/**
	 * Xử lý khi comment được sửa
	 */
	public function handle_comment_edit($comment_id, $commentdata) {
		try {
			// Lấy thông tin comment
			$comment = get_comment($comment_id);

			// Xóa cache liên quan đến comment
			$this->purge_cache_for_comment($comment);
		} catch (Exception $e) {
			$this->log_message("Lỗi xử lý sửa comment: " . $e->getMessage());
		}
	}
	/**
	 * Xử lý khi comment bị xóa hoàn toàn
	 */
	public function handle_comment_deletion($comment_id, $comment) {
		try {
			// Chỉ clear cache nếu comment đang ở trạng thái approved
			if ($comment->comment_approved === '1') {
				$this->purge_cache_for_comment($comment);
			}
		} catch (Exception $e) {
			$this->log_message("Lỗi xử lý xóa comment: " . $e->getMessage());
		}
	}

	/**
	 * Xóa cache liên quan đến comment
	 */
	private function purge_cache_for_comment($comment) {
		try {
			// Lấy post ID từ comment
			$post_id = $comment->comment_post_ID;

			// Lấy URL của bài viết
			$post_url = get_permalink($post_id);

			if ($post_url) {
				// Xóa cache của URL bài viết
				$this->schedule_urls_processing([$post_url]);

				// Nếu có trang phân trang comment, xóa cache của các trang phân trang đó
				$comment_pages = get_comment_pages_count($comment);
				if ($comment_pages > 1) {
					for ($i = 2; $i <= $comment_pages; $i++) {
						$this->schedule_urls_processing([$post_url . 'comment-page-' . $i . '/']);
					}
				}
			}
		} catch (Exception $e) {
			$this->log_message("Lỗi xóa cache cho comment: " . $e->getMessage());
		}
	}	

	public function handle_save_post($post_id, $post, $update, $post_before) {
	   try {
		   // Chặn nếu là bản revision, autosave hoặc đang trong quá trình autosave
		   if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			   return;
		   }
		   // Chặn nếu bài viết đang là draft, pending hoặc auto-draft (vì chưa public, không cần xóa cache)
		   if (in_array($post->post_status, ['draft', 'pending', 'auto-draft'])) {
			   return;
		   }
		   // Chỉ xử lý nếu post_type thuộc danh sách được hỗ trợ
		   if (in_array($post->post_type, $this->supported_post_types)) {
			   // Lên lịch xử lý URL cache bất đồng bộ
			   as_enqueue_async_action('process_schedule_urls', [
				   'post_id' => $post_id, 
				   'post_type' => $post->post_type, 
				   'update' => $update
			   ]);
		   }
	   } catch (Exception $e) {
		   $this->log_message("Lỗi xử lý lưu bài viết: " . $e->getMessage());
	   }
	}

	# Collect URL bất đồng bộ
	public function process_schedule_urls($post_id, $post_type, $update) {
	   try {
		   $urls = [];
		   if ($post_type === 'page') {
			   $urls = $this->collect_urls_for_page($post_id);
		   } else {
			   $urls = $this->collect_urls_for_post($post_id, $update);
		   }
		   // Lên lịch xử lý URL cache
		   $this->schedule_urls_processing($urls);
	   } catch (Exception $e) {
		   $this->log_message("Lỗi xử lý lưu bài viết: " . $e->getMessage());
	   }
	}

    public function handle_before_delete_post($post_id) {
        try {
            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, $this->supported_post_types)) {
                return;
            }
            $permalink = get_permalink($post_id);
            if ($permalink) {
                $this->schedule_urls_processing([$permalink]);
            }
        } catch (Exception $e) {
            $this->log_message("Lỗi xử lý xóa bài viết: " . $e->getMessage());
        }
    }

    public function handle_trash_post($post_id) {
        try {
            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, $this->supported_post_types)) {
                return;
            }
            $permalink = get_permalink($post_id);
            if ($permalink) {
                $this->schedule_urls_processing([$permalink]);
            }
        } catch (Exception $e) {
            $this->log_message("Lỗi xử lý bài viết vào thùng rác: " . $e->getMessage());
        }
    }

    public function handle_edit_term($term_id, $tt_id, $taxonomy) {
        try {
            if (in_array($taxonomy, ['category', 'post_tag'])) {
                $term_link = get_term_link($term_id, $taxonomy);
                if (!is_wp_error($term_link)) {
                    $this->schedule_urls_processing([$term_link]);
                }
            }
        } catch (Exception $e) {
            $this->log_message("Lỗi xử lý chỉnh sửa term: " . $e->getMessage());
        }
    }

    public function handle_delete_term($term_id, $tt_id, $taxonomy, $deleted_term) {
        try {
            if (in_array($taxonomy, ['category', 'post_tag'])) {
                $term_link = get_term_link($term_id, $taxonomy);
                if (!is_wp_error($term_link)) {
                    $this->schedule_urls_processing([$term_link]);
                }
            }
        } catch (Exception $e) {
            $this->log_message("Lỗi xử lý xóa term: " . $e->getMessage());
        }
    }

// Quá trình sử lý clear cache và preload cache tự động
private function schedule_urls_processing($urls) {
    if (empty($urls)) return;

    // Lọc các URL trùng lặp
    $unique_urls = array_unique($urls);

    // Chuyển toàn bộ URL vào quá trình Clear Cache
    as_enqueue_async_action(self::ACTION_PURGE_URLS, ['urls' => $unique_urls]);

    // Chuyển toàn bộ URL vào quá trình Preload Cache
    as_enqueue_async_action(self::ACTION_PRELOAD_URLS, ['urls' => $unique_urls]);
}

public function process_purge_urls_batch($urls) {
    try {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['email']) || empty($credentials['api_key']) || empty($credentials['zone_id'])) {
            throw new Exception("Thiếu thông tin xác thực Cloudflare API");
        }

        $batches = array_chunk($urls, self::MAX_URLS_PER_BATCH);
        $requests = [];
        
     #   $this->log_message("Bắt đầu gửi yêu cầu xóa cache cho các URL: " . implode(', ', $urls));

        foreach ($batches as $batch) {
            $requests[] = [
                'body' => json_encode(['files' => $batch]),
                'headers' => [
                    'X-Auth-Email' => $credentials['email'],
                    'X-Auth-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json',
                ]
            ];
            $this->log_message("Gửi batch xóa cache: " . implode(', ', $batch));
        }
        
        // Sử dụng wp_remote_post không đồng bộ
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        
        foreach ($requests as $request) {
            $ch = curl_init('https://api.cloudflare.com/client/v4/zones/' . $credentials['zone_id'] . '/purge_cache');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Auth-Email: ' . $credentials['email'],
                'X-Auth-Key: ' . $credentials['api_key'],
                'Content-Type: application/json',
            ]);
            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[] = $ch;
        }
        
        do {
            curl_multi_exec($multi_handle, $active);
        } while ($active > 0);
        
        foreach ($curl_handles as $ch) {
            $response = curl_multi_getcontent($ch);
            curl_close($ch);
        }
        
        curl_multi_close($multi_handle);
        
        $this->log_message("Đã gửi tất cả yêu cầu xóa cache đồng thời cho các batch");
    } catch (Exception $e) {
        $this->log_message("Lỗi xóa cache: " . $e->getMessage());
        as_enqueue_async_action(self::ACTION_PURGE_URLS, ['urls' => $urls], 'cloudflare-cache');
    }
}

public function process_preload_urls_batch($urls) {
    try {
        $pid = getmypid();
        
        // Giảm mức độ ưu tiên của tiến trình để tránh ảnh hưởng hệ thống
        if (function_exists('proc_nice')) {
            proc_nice(19);
            $this->log_message("Đã giảm ưu tiên CPU cho PID: $pid");
        }
        if (function_exists('shell_exec')) {
            shell_exec('ionice -c3 -p ' . $pid);
            $this->log_message("Đã giảm ưu tiên I/O cho PID: $pid");
        }

        // Xác định số CPU lõi của hệ thống
        $num_cores = intval(shell_exec('nproc 2>/dev/null') ?: 1); // Mặc định là 1 nếu không lấy được

        // Lấy mức tải CPU trung bình trong 1 phút
        $load = sys_getloadavg();
        $cpu_load = $load[0] ?? 0;

        // Tính toán số lượng request đồng thời dựa trên tải CPU và số lõi CPU
        $max_concurrent_requests = max(1, intval($num_cores * 2 - $cpu_load));

        // Giới hạn số lượng request đồng thời trong khoảng hợp lý
        $max_concurrent_requests = min(max($max_concurrent_requests, 1), 10);

        $this->log_message("Số request đồng thời được tính toán: $max_concurrent_requests (CPU Load: $cpu_load, Cores: $num_cores)");

        // Chia nhỏ danh sách URL thành các chunk
        $chunks = array_chunk($urls, $max_concurrent_requests);
        foreach ($chunks as $chunk) {
            $this->process_preload_urls_with_curl_multi($chunk);
        }
    } catch (Exception $e) {
        $this->log_message("Lỗi preload cache: " . $e->getMessage());
        as_enqueue_async_action(self::ACTION_PRELOAD_URLS, ['urls' => $urls], 'cloudflare-cache');
    }
}

private function process_preload_urls_with_curl_multi($urls) {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($urls as $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Cache Preloader');
        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }

    // Xử lý nhiều request song song nhưng không tiêu tốn quá nhiều CPU
    $active = null;
    do {
        while (($status = curl_multi_exec($mh, $active)) == CURLM_CALL_MULTI_PERFORM);
        
        if ($status != CURLM_OK) {
            break;
        }

        // Chờ request xử lý thay vì vòng lặp rỗng làm tốn CPU
        curl_multi_select($mh, 0.1);
    } while ($active);

    // Xử lý kết quả trả về
    foreach ($handles as $url => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->log_message("Đã preload thành công: " . $url);
        } else {
            $error = curl_error($ch);
            $this->log_message("Lỗi preload URL {$url}: " . $error);
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
}



private function collect_urls_for_page($post_id) {
    $urls = [];
    try {
        $permalink = get_permalink($post_id);
        if ($permalink) {
            $urls[] = $permalink;
        }
        $this->log_message("Đã thu thập URL cho trang: " . implode(', ', $urls));
    } catch (Exception $e) {
        $this->log_message("Lỗi thu thập URL trang: " . $e->getMessage());
    }
    return $urls;
}

private function collect_urls_for_post($post_id, $update) {
    $urls = [];
    try {
        $permalink = get_permalink($post_id);
        if ($permalink) {
            $urls[] = $permalink;
        }

        $urls[] = home_url('/');
        $this->add_paginated_urls($urls, home_url('/'), $this->get_total_pages());

        $old_categories = get_post_meta($post_id, '_old_categories', true) ?: [];
        $old_tags = get_post_meta($post_id, '_old_tags', true) ?: [];
        
        $new_category_ids = wp_get_post_categories($post_id, ['fields' => 'ids']);
        $new_tag_ids = wp_get_post_tags($post_id, ['fields' => 'ids']);
        
        $categories_to_purge = array_unique(array_merge($old_categories, $new_category_ids));
        $tags_to_purge = array_unique(array_merge($old_tags, $new_tag_ids));

        foreach ($categories_to_purge as $category_id) {
            $category_link = get_category_link($category_id);
            if ($category_link) {
                $urls[] = $category_link;
                $this->add_paginated_urls($urls, $category_link, $this->get_total_pages_in_category($category_id));
            }
        }

        foreach ($tags_to_purge as $tag_id) {
            $tag_link = get_tag_link($tag_id);
            if ($tag_link) {
                $urls[] = $tag_link;
                $this->add_paginated_urls($urls, $tag_link, $this->get_total_pages_in_tag($tag_id));
            }
        }

        if ($update) {
            update_post_meta($post_id, '_old_categories', $new_category_ids);
            update_post_meta($post_id, '_old_tags', $new_tag_ids);
        } else {
            add_post_meta($post_id, '_old_categories', $new_category_ids, true);
            add_post_meta($post_id, '_old_tags', $new_tag_ids, true);
        }

            $options = get_option('cloudflare_cache_options');
            $custom_urls = array_filter(explode("\n", $options['custom_urls'] ?? ''));
			foreach ($custom_urls as $custom_url) {
				$custom_url = trim($custom_url);
				if (preg_match('/^(https?:\/\/)/', $custom_url)) {
					// Nếu là URL đầy đủ, giữ nguyên
					$urls[] = $custom_url;
				} elseif (strpos($custom_url, '/') === 0) {
					// Nếu là đường dẫn tương đối, thêm domain
					$urls[] = home_url($custom_url);
				}
			}


        $this->log_message("Đã thu thập URL cho bài viết: " . implode(', ', $urls));
    } catch (Exception $e) {
        $this->log_message("Lỗi thu thập URL bài viết: " . $e->getMessage());
    }
    return array_unique($urls); // Đảm bảo các URL là duy nhất
}

    private function add_paginated_urls(&$urls, $base_url, $total_pages) {
        for ($i = 2; $i <= $total_pages; $i++) {
            $urls[] = trailingslashit($base_url) . "page/$i/";
        }
    }

    private function get_total_pages() {
        try {
            $total_posts = wp_count_posts()->publish;
            $posts_per_page = get_option('posts_per_page');
            return ceil($total_posts / $posts_per_page);
        } catch (Exception $e) {
            $this->log_message("Lỗi tính tổng số trang: " . $e->getMessage());
            return 1;
        }
    }

    private function get_total_pages_in_category($category_id) {
        try {
            $category = get_category($category_id);
            if (!$category || is_wp_error($category)) {
                return 0;
            }
            $total_posts = $category->count;
            $posts_per_page = get_option('posts_per_page');
            return ceil($total_posts / $posts_per_page);
        } catch (Exception $e) {
            $this->log_message("Lỗi tính tổng số trang danh mục: " . $e->getMessage());
            return 1;
        }
    }

    private function get_total_pages_in_tag($tag_id) {
        try {
            $tag = get_term($tag_id);
            if (!$tag || is_wp_error($tag)) {
                return 0;
            }
            $total_posts = $tag->count;
            $posts_per_page = get_option('posts_per_page');
            return ceil($total_posts / $posts_per_page);
        } catch (Exception $e) {
            $this->log_message("Lỗi tính tổng số trang tag: " . $e->getMessage());
            return 1;
        }
    }

    private function collect_urls_to_purge($post) {
        $urls = [];
        if ($post->post_type === 'page') {
            $urls = $this->collect_urls_for_page($post->ID);
        } else {
            $urls = $this->collect_urls_for_post($post->ID, true);
        }
        return $urls;
    }

    private function get_api_credentials() {
        $options = get_option('cloudflare_cache_options', []);
        return [
            'email' => $options['email'] ?? '',
            'api_key' => $options['api_key'] ?? '',
            'zone_id' => $options['zone_id'] ?? '',
        ];
    }
	
public function add_admin_bar_cache_buttons() {
    global $wp_admin_bar;

    // Kiểm tra quyền truy cập
    if (!current_user_can('manage_options')) {
        return;
    }

    // Nút Clear Cache
    $wp_admin_bar->add_node([
        'id' => 'cloudflare-clear-cache',
        'title' => '🗑️ Clear Cache',
        'href' => wp_nonce_url(admin_url('admin-post.php?action=cloudflare_clear_cache'), 'cloudflare_clear_cache'),
        'parent' => 'top-secondary', // Nhóm top-secondary
        'meta' => ['title' => 'Xóa toàn bộ cache Cloudflare']
    ]);

    // Nút Preload Cache
    $wp_admin_bar->add_node([
        'id' => 'cloudflare-preload-cache',
        'title' => '♻️ Preload Cache',
        'href' => wp_nonce_url(admin_url('admin-post.php?action=cloudflare_preload_cache'), 'cloudflare_preload_cache'),
        'parent' => 'top-secondary', // Nhóm top-secondary
        'meta' => ['title' => 'Preload cache từ sitemap']
    ]);
}



    public function display_admin_notices() {
        $message = get_transient('cf_cache_message');
        if ($message) {
            delete_transient('cf_cache_message');
            $class = $message['type'] === 'error' ? 'notice-error' : ($message['type'] === 'info' ? 'notice-info' : 'notice-success');
            ?>
            <div class="notice <?php echo $class; ?> is-dismissible">
                <p><?php echo esc_html($message['message']); ?></p>
            </div>
            <?php
        }
    }

    public function handle_cloudflare_clear_cache() {
        // Kiểm tra nonce
        check_admin_referer('cloudflare_clear_cache');
        
        // Kiểm tra quyền
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền thực hiện thao tác này.');
        }

        try {
            $credentials = $this->get_api_credentials();
            
            if (empty($credentials['email']) || empty($credentials['api_key']) || empty($credentials['zone_id'])) {
                throw new Exception("Thiếu thông tin xác thực Cloudflare API");
            }

            $response = wp_remote_post(
                'https://api.cloudflare.com/client/v4/zones/' . $credentials['zone_id'] . '/purge_cache',
                [
                    'headers' => [
                        'X-Auth-Email' => $credentials['email'],
                        'X-Auth-Key' => $credentials['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode(['purge_everything' => true]),
                    'timeout' => 30,
                ]
            );

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['success'])) {
                throw new Exception("API Cloudflare trả về lỗi: " . json_encode($body['errors'] ?? []));
            }

            $this->log_message("Đã xóa toàn bộ cache Cloudflare thành công");
            
            set_transient('cf_cache_message', [
                'type' => 'success', 
                'message' => 'Đã xóa toàn bộ cache Cloudflare thành công!'
            ], 30);
        } catch (Exception $e) {
            $this->log_message("Lỗi xóa toàn bộ cache: " . $e->getMessage());
            
            set_transient('cf_cache_message', [
                'type' => 'error', 
                'message' => 'Không thể xóa cache: ' . $e->getMessage()
            ], 30);
        }

    // Sử dụng JS redirect để tránh hook WordPress
    ?>
    <script>window.location.href='<?php echo wp_get_referer(); ?>';</script>
    <?php
    exit;
    }

public function handle_cloudflare_preload_cache() {
    check_admin_referer('cloudflare_preload_cache');
    
    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền thực hiện thao tác này.');
    }

    as_enqueue_async_action(
        'cloudflare_preload_sitemap_action', 
        [], 
        'cloudflare-sitemap-preload'
    );

    set_transient('cf_cache_message', [
        'type' => 'info', 
        'message' => 'Đã lên lịch preload cache từ sitemap.'
    ], 30);

    // Sử dụng JS redirect để tránh hook WordPress
    ?>
    <script>window.location.href='<?php echo wp_get_referer(); ?>';</script>
    <?php
    exit;
}


    public function preload_sitemap_urls() {
        try {
            $sitemap_url = home_url('/sitemap.xml');
            $urls_to_preload = $this->extract_urls_from_sitemap($sitemap_url);

            if (empty($urls_to_preload)) {
                throw new Exception("Không tìm thấy URL nào trong sitemap");
            }

            $this->log_message("Đã tìm thấy " . count($urls_to_preload) . " URL để preload");

            // Chia URLs thành batch để preload
            $batches = array_chunk($urls_to_preload, 30);
            foreach ($batches as $batch) {
                $this->process_preload_urls_batch($batch);
            }

            $this->log_message("Hoàn tất preload toàn bộ URL từ sitemap");
        } catch (Exception $e) {
            $this->log_message("Lỗi preload sitemap: " . $e->getMessage());
        }
    }

    private function extract_urls_from_sitemap($sitemap_url) {
        $urls = [];
        try {
            $sitemap_content = wp_remote_get($sitemap_url);
            
            if (is_wp_error($sitemap_content)) {
                throw new Exception("Không thể tải sitemap: " . $sitemap_content->get_error_message());
            }

            $sitemap_body = wp_remote_retrieve_body($sitemap_content);
            $xml = simplexml_load_string($sitemap_body);

            if ($xml === false) {
                throw new Exception("Không thể phân tích XML sitemap");
            }

            // Xử lý sitemap gốc hoặc sitemap con
            if (isset($xml->sitemap)) {
                // Đây là sitemap index, phải đọc các sitemap con
                foreach ($xml->sitemap as $sub_sitemap) {
                    $sub_urls = $this->extract_urls_from_sitemap((string)$sub_sitemap->loc);
                    $urls = array_merge($urls, $sub_urls);
                }
            } else {
                // Đây là sitemap chứa URLs
                foreach ($xml->url as $url_entry) {
                    $urls[] = (string)$url_entry->loc;
                }
            }
        } catch (Exception $e) {
            $this->log_message("Lỗi trích xuất URL từ sitemap: " . $e->getMessage());
        }

        return $urls;
    }

    public function register_preload_sitemap_action() {
        add_action('cloudflare_preload_sitemap_action', [$this, 'preload_sitemap_urls']);
    }
	
	
	// Lên lịch cron job
public function schedule_cloudflare_preload_cache() {
    // Xóa cron job cũ nếu tồn tại
    if (wp_next_scheduled('cloudflare_daily_preload_cache')) {
        wp_clear_scheduled_hook('cloudflare_daily_preload_cache');
    }

    // Lấy cài đặt hiện tại
    $options = get_option('cloudflare_cache_options');

    // Kiểm tra nếu Auto Preload được bật
    if (!empty($options['auto_preload']) && $options['auto_preload'] === '1') {
        $preload_time = isset($options['auto_preload_time']) ? $options['auto_preload_time'] : '02:00'; // Mặc định 02:00
        $timezone = wp_timezone();

        // Tạo đối tượng DateTime với múi giờ của WordPress
        $scheduled_time = new DateTime('today ' . $preload_time, $timezone);

        // Kiểm tra nếu thời gian đã qua, đặt lịch cho ngày mai
        if ($scheduled_time->getTimestamp() < time()) {
            $scheduled_time->modify('tomorrow ' . $preload_time);
        }

        // Lấy timestamp để lên lịch cron job
        $scheduled_timestamp = $scheduled_time->getTimestamp();

        // Chỉ đặt cron nếu chưa có
        if (!wp_next_scheduled('cloudflare_daily_preload_cache')) {
            wp_schedule_event($scheduled_timestamp, 'daily', 'cloudflare_daily_preload_cache');
            $this->log_message("Đã lên lịch cron job tại: " . $scheduled_time->format('Y-m-d H:i:s'));
        } else {
            $this->log_message("Cron job đã được lên lịch trước đó.");
        }
    }
}

    // Xử lý cron job
public function handle_cloudflare_daily_preload_cache() {
    // Lấy cài đặt hiện tại
    $options = get_option('cloudflare_cache_options');

    // Kiểm tra xem cron đã chạy hôm nay chưa, tránh chạy lặp
    if (!empty($options['last_preload_run']) && $options['last_preload_run'] === date('Y-m-d')) {
        $this->log_message("Cron preload cache đã chạy hôm nay, bỏ qua lần chạy này.");
        return;
    }

    // Đánh dấu đã chạy cron hôm nay
    $options['last_preload_run'] = date('Y-m-d');
    update_option('cloudflare_cache_options', $options);

    $this->log_message("Bắt đầu chạy preload sitemap theo lịch");

    as_enqueue_async_action(
        'cloudflare_preload_sitemap_action',
        [],
        'cloudflare-sitemap-preload'
    );

    set_transient('cf_cache_message', [
        'type' => 'info',
        'message' => 'Đã lên lịch preload cache tự động. Vui lòng kiểm tra nhật ký để biết chi tiết.'
    ], 30);
}
	

    // Cập nhật cron job khi thay đổi thời gian
public function update_cloudflare_preload_cron($old_value, $new_value) {
    // Kiểm tra nếu auto_preload hoặc auto_preload_time thay đổi
    if ($old_value['auto_preload'] !== $new_value['auto_preload'] || $old_value['auto_preload_time'] !== $new_value['auto_preload_time']) {
        if ($new_value['auto_preload'] === '1') {
            wp_clear_scheduled_hook('cloudflare_daily_preload_cache');
            $preload_time = isset($new_value['auto_preload_time']) ? $new_value['auto_preload_time'] : '02:00'; // Mặc định 02:00
            $timezone = wp_timezone();

            // Tạo đối tượng DateTime với múi giờ của WordPress
            $scheduled_time = new DateTime('today ' . $preload_time, $timezone);

            // Kiểm tra nếu thời gian đã qua, đặt lịch cho ngày mai
            if ($scheduled_time->getTimestamp() < time()) {
                $scheduled_time->modify('tomorrow ' . $preload_time);
            }

            // Lấy timestamp để lên lịch cron job
            $scheduled_timestamp = $scheduled_time->getTimestamp();

            // Đặt lại cron job
            wp_schedule_event($scheduled_timestamp, 'daily', 'cloudflare_daily_preload_cache');
            $this->log_message("Đã cập nhật cron job tại: " . $scheduled_time->format('Y-m-d H:i:s'));
        } else {
            wp_clear_scheduled_hook('cloudflare_daily_preload_cache');
            $this->log_message("Auto Preload không được bật, đã xóa cron job.");
        }
    }
}

    // Xóa cron job khi plugin bị vô hiệu hóa
    public function deactivate_cloudflare_preload_cache() {
        wp_clear_scheduled_hook('cloudflare_daily_preload_cache');
    }
	
	
	###########GIAO DIỆn ##############
	
 // Thêm menu admin
    public function add_admin_menu() {
        add_management_page(
            'Cloudflare Auto Cache Purge And Preload', // Tiêu đề trang
            'Cloudflare Auto Cache Purge And Preload', // Tiêu đề menu
            'manage_options', // Quyền truy cập
            'cloudflare-cache-settings', // Slug menu
            [$this, 'render_settings_page'] // Callback hiển thị trang
        );
    }
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('tools.php?page=cloudflare-cache-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }	

    // Đăng ký settings
    public function register_settings() {
        register_setting('cloudflare_cache_settings', 'cloudflare_cache_options', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        // Section API Cloudflare
        add_settings_section(
            'cloudflare_api_settings',
            'Cài đặt Cloudflare API',
            [$this, 'render_section_api'],
            'cloudflare-cache-settings'
        );

        // Các field API
        add_settings_field(
            'cloudflare_email',
            'Cloudflare API Email',
            [$this, 'render_email_field'],
            'cloudflare-cache-settings',
            'cloudflare_api_settings'
        );
        add_settings_field(
            'cloudflare_api_key',
            'Cloudflare API Key',
            [$this, 'render_api_key_field'],
            'cloudflare-cache-settings',
            'cloudflare_api_settings'
        );
        add_settings_field(
            'cloudflare_zone_id',
            'Cloudflare Zone ID',
            [$this, 'render_zone_id_field'],
            'cloudflare-cache-settings',
            'cloudflare_api_settings'
        );

        // Section URL Settings
        add_settings_section(
            'url_settings',
            'Cài đặt URL',
            [$this, 'render_section_url'],
            'cloudflare-cache-settings'
        );
        add_settings_field(
            'sitemap_url',
            'Sitemap URL',
            [$this, 'render_sitemap_field'],
            'cloudflare-cache-settings',
            'url_settings'
        );
        add_settings_field(
            'custom_urls',
            'Custom URLs để Purge',
            [$this, 'render_custom_urls_field'],
            'cloudflare-cache-settings',
            'url_settings'
        );

        // Section Other Settings
        add_settings_section(
            'other_settings',
            'Cài đặt khác',
            [$this, 'render_section_other'],
            'cloudflare-cache-settings'
        );
        add_settings_field(
            'enable_logging',
            'Logging',
            [$this, 'render_logging_field'],
            'cloudflare-cache-settings',
            'other_settings'
        );
		        // Field Auto Preload
        add_settings_field(
            'auto_preload',
            'Auto Preload',
            [$this, 'render_auto_preload_field'],
            'cloudflare-cache-settings',
            'other_settings'
        );
        add_settings_field(
            'auto_preload_time',
            'Thời gian chạy Auto Preload',
            [$this, 'render_auto_preload_time_field'],
            'cloudflare-cache-settings',
            'other_settings'
        );
    }

    // Render trang settings
public function render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Cài đặt Cloudflare Auto Cache Purge And Preload</h1>

            <!-- Hiển thị các thông báo lỗi nếu có -->
        <?php settings_errors('cloudflare_cache_settings'); ?>

        <form action="options.php" method="post">
            <?php
            settings_fields('cloudflare_cache_settings');
            do_settings_sections('cloudflare-cache-settings');
            submit_button('Lưu cài đặt');
            ?>
        </form>

        <hr>
        <h2>Reset về mặc định</h2>
        <p>Nhấn nút dưới đây để khôi phục về cài đặt mặc định.</p>

        <!-- Form reset -->
        <form action="" method="post">
            <?php
            $nonce = wp_create_nonce('cloudflare_reset_settings');
            ?>
            <input type="hidden" name="action" value="reset_cloudflare_settings">
            <input type="hidden" name="_wpnonce" value="<?php echo $nonce; ?>">
            <button type="submit" class="button button-secondary">
                Reset về mặc định
            </button>
        </form>
    </div>

    <!-- JavaScript để xóa tham số settings-reset khỏi URL -->
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('settings-reset')) {
                urlParams.delete('settings-reset');
                const newUrl = window.location.pathname + '?' + urlParams.toString();
                window.history.replaceState({}, '', newUrl);
            }
        });
    </script>
    <!-- JavaScript để toggle hiển thị trường thời gian -->
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Lấy các phần tử liên quan
        const autoPreloadCheckbox = $('#auto_preload');
        const autoPreloadTimeField = $('#auto_preload_time').closest('tr');

        // Hàm kiểm tra trạng thái checkbox
        function toggleAutoPreloadTimeField() {
            if (autoPreloadCheckbox.is(':checked')) {
                autoPreloadTimeField.show();
            } else {
                autoPreloadTimeField.hide();
            }
        }

        // Khởi tạo trạng thái ban đầu
        toggleAutoPreloadTimeField();

        // Sự kiện khi checkbox thay đổi
        autoPreloadCheckbox.on('change', function() {
            toggleAutoPreloadTimeField();
        });
    });
    </script>

    <?php
}


    // Render các section
    public function render_section_api() {
        echo '<p>Nhập thông tin xác thực Cloudflare API của bạn:</p>';
    }

    public function render_section_url() {
        echo '<p>Cấu hình các URL cần xử lý:</p>';
    }

    public function render_section_other() {
        echo '<p>Các cài đặt khác:</p>';
    }

    // Render các field
    public function render_email_field() {
        $options = get_option('cloudflare_cache_options');
        ?>
        <input type="email" id="cloudflare_email" name="cloudflare_cache_options[email]"
               value="<?php echo esc_attr($options['email'] ?? ''); ?>" class="regular-text" required>
        <?php
    }

    public function render_api_key_field() {
        $options = get_option('cloudflare_cache_options');
        ?>
        <input type="password" id="cloudflare_api_key" name="cloudflare_cache_options[api_key]"
               value="<?php echo esc_attr($options['api_key'] ?? ''); ?>" class="regular-text" required>
        <?php
    }

    public function render_zone_id_field() {
        $options = get_option('cloudflare_cache_options');
        ?>
        <input type="text" id="cloudflare_zone_id" name="cloudflare_cache_options[zone_id]"
               value="<?php echo esc_attr($options['zone_id'] ?? ''); ?>" class="regular-text" required>
        <?php
    }

    public function render_sitemap_field() {
        $options = get_option('cloudflare_cache_options');
        ?>
        <input type="url" id="sitemap_url" name="cloudflare_cache_options[sitemap_url]"
               value="<?php echo esc_url($options['sitemap_url'] ?? home_url('/sitemap.xml')); ?>" class="regular-text">
        <?php
    }

    public function render_custom_urls_field() {
        $options = get_option('cloudflare_cache_options');
        ?>
        <textarea id="custom_urls" name="cloudflare_cache_options[custom_urls]" rows="5" cols="50" class="large-text"><?php
            echo esc_textarea($options['custom_urls'] ?? '');
        ?></textarea>
        <p class="description">Nhập mỗi URL trên một dòng, sử dụng đường dẫn tương đối (ví dụ: /archives/) hoặc URL tuyệt đối (ví dụ: <?php echo home_url('/archives/'); ?>)</p>
        <?php
    }

// Render Logging Field
public function render_logging_field() {
    $options = get_option('cloudflare_cache_options');
    ?>
    <input type="checkbox" id="enable_logging" name="cloudflare_cache_options[enable_logging]"
           value="1" <?php checked(($options['enable_logging'] ?? '0'), '1'); ?>>
    <?php
}

// Render Auto Preload Field
public function render_auto_preload_field() {
    $options = get_option('cloudflare_cache_options');
    ?>
    <input type="checkbox" id="auto_preload" name="cloudflare_cache_options[auto_preload]"
           value="1" <?php checked(($options['auto_preload'] ?? '0'), '1'); ?>>
    <?php
}
// Render Auto Preload Time Field
public function render_auto_preload_time_field() {
    $options = get_option('cloudflare_cache_options');
    $selected_time = $options['auto_preload_time'] ?? '02:00'; // Giá trị mặc định là 02:00
    ?>
    <select id="auto_preload_time" name="cloudflare_cache_options[auto_preload_time]">
        <?php for ($hour = 0; $hour <= 23; $hour++): ?>
            <option value="<?php echo sprintf('%02d:00', $hour); ?>" <?php selected($selected_time, sprintf('%02d:00', $hour)); ?>>
                <?php echo sprintf('%02d:00', $hour); ?>
            </option>
        <?php endfor; ?>
    </select>
    <p class="description">Chọn giờ để chạy Auto Preload (theo múi giờ WordPress).</p>
    <?php
}

public function sanitize_settings($input, $is_reset = false) {
    $sanitized = [];
	
	    // Kiểm tra nếu đang thực hiện reset
    if (isset($_POST['action']) && $_POST['action'] === 'reset_cloudflare_settings') {
        return $input; // Bỏ qua kiểm tra API khi reset
    }

    // Email Cloudflare
    $sanitized['email'] = isset($input['email']) ? sanitize_email($input['email']) : '';

    // API Key
    $sanitized['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

    // Zone ID
    $sanitized['zone_id'] = isset($input['zone_id']) ? sanitize_text_field($input['zone_id']) : '';

    // Nếu không phải đang reset, kiểm tra tính hợp lệ của API
    if (!$is_reset) {
        // Kiểm tra nếu thiếu một trong ba giá trị quan trọng
        if (empty($sanitized['email']) || empty($sanitized['api_key']) || empty($sanitized['zone_id'])) {
            add_settings_error(
                'cloudflare_cache_settings',
                'cloudflare_cache_settings_error',
                'Vui lòng nhập đầy đủ Email, API Key và Zone ID.',
                'error'
            );
            return get_option('cloudflare_cache_options'); // Trả về giá trị cũ để tránh mất dữ liệu
        }

        // Kiểm tra tính hợp lệ của API credentials
        $validation_result = $this->validate_api_credentials($sanitized['email'], $sanitized['api_key'], $sanitized['zone_id']);
        if (!$validation_result['success']) {
            add_settings_error(
                'cloudflare_cache_settings',
                'cloudflare_cache_settings_error',
                'Thông tin Cloudflare không hợp lệ: ' . $validation_result['message'],
                'error'
            );
            return get_option('cloudflare_cache_options');
        }
		
    }

// Tự động phát hiện sitemap nếu không nhập
    $sanitized['sitemap_url'] = !empty($input['sitemap_url']) ? esc_url_raw($input['sitemap_url']) : $this->detect_sitemap_url();
	
	    // Custom URLs
    $sanitized['custom_urls'] = isset($input['custom_urls']) ? sanitize_textarea_field($input['custom_urls']) : '';

    // Enable Logging (Checkbox) - FIXED
    $sanitized['enable_logging'] = !empty($input['enable_logging']) ? '1' : '0';

    // Auto Preload (Checkbox) - FIXED
    $sanitized['auto_preload'] = !empty($input['auto_preload']) ? '1' : '0';

    // Auto Preload Time (Dropdown) - FIXED
    if (isset($input['auto_preload_time']) && preg_match('/^\d{2}:\d{2}$/', $input['auto_preload_time'])) {
        $sanitized['auto_preload_time'] = sanitize_text_field($input['auto_preload_time']);
    } else {
        $sanitized['auto_preload_time'] = '02:00'; // Giá trị mặc định nếu không hợp lệ
    }

    return $sanitized;
}

/**
 * Validate Cloudflare API credentials
 */
private function validate_api_credentials($email, $api_key, $zone_id) {
    $response = wp_remote_get('https://api.cloudflare.com/client/v4/zones/' . $zone_id, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_key,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Lỗi kết nối tới Cloudflare: ' . $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['success']) && $body['success'] === true) {
        return ['success' => true, 'message' => 'Thông tin API hợp lệ.'];
    } else {
        // Kiểm tra các lỗi cụ thể từ Cloudflare
        $errors = !empty($body['errors']) ? array_map(function ($error) {
            // Kiểm tra lỗi Zone ID không hợp lệ
            if (strpos($error['message'], 'Could not route to') !== false) {
                return 'Zone ID không hợp lệ. Vui lòng kiểm tra lại Zone ID của bạn.';
            }
            // Kiểm tra lỗi API Key hoặc Email không hợp lệ
            if (strpos($error['message'], 'Unknown X-Auth-Key or X-Auth-Email') !== false) {
                return 'API Key hoặc Email không hợp lệ. Vui lòng kiểm tra lại thông tin đăng nhập của bạn.';
            }
            // Trả về thông báo lỗi mặc định nếu không phải là lỗi Zone ID hoặc API Key/Email
            return $error['message'];
        }, $body['errors']) : ['Lỗi không xác định'];
        
        return ['success' => false, 'message' => implode('; ', $errors)];
    }
}

/**
 * Tự động phát hiện Sitemap từ mã nguồn trang chủ
 */
private function detect_sitemap_url() {
    // 1. Kiểm tra các sitemap phổ biến
$possible_sitemaps = [
    '/sitemap.xml', // Mặc định của nhiều plugin
    '/sitemap_index.xml', // Yoast SEO
    '/sitemap/sitemap.xml', // Rank Math SEO
    '/sitemaps/sitemap.xml', // Một số plugin khác
    '/sitemap.php', // Phiên bản cũ hoặc plugin tùy chỉnh
    '/sitemap.txt', // Phiên bản sitemap dạng văn bản
    '/wp-sitemap.xml', // Sitemap mặc định từ WordPress 5.5+
    '/sitemap.xml.gz', // Sitemap dạng nén (Google XML Sitemaps)
    '/sitemap-main.xml', // All in One SEO Pack
    '/sitemap_index.xml.gz', // Phiên bản nén của Yoast SEO
    '/sitemap-index.xml', // SEOPress
    '/sitemap-news.xml', // Sitemap tin tức (Yoast, Rank Math)
    '/sitemap-video.xml', // Sitemap video (Yoast, Rank Math)
    '/sitemap-image.xml', // Sitemap hình ảnh (Yoast, Rank Math)
];

    foreach ($possible_sitemaps as $sitemap) {
        $url = home_url($sitemap);
        $response = wp_remote_head($url, ['timeout' => 5]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            return esc_url_raw($url);
        }
    }

    // 2. Kiểm tra Sitemap trong mã nguồn trang chủ
    $home_url = home_url('/');
    $response = wp_remote_get($home_url, ['timeout' => 5]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $html = wp_remote_retrieve_body($response);

        // Tìm thẻ <link rel="sitemap" href="...">
        if (preg_match('/<link[^>]+rel=["\']sitemap["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            return esc_url_raw($matches[1]);
        }

        // Tìm sitemap xuất hiện trong thẻ <loc> của XML
        if (preg_match('/<loc>(https?:\/\/[^<]+\/sitemap[^<]*)<\/loc>/i', $html, $matches)) {
            return esc_url_raw($matches[1]);
        }
    }

    // 3. Nếu không tìm thấy, trả về sitemap mặc định
    return home_url('/sitemap.xml');
}


public function handle_reset_settings() {
    if (isset($_POST['action']) && $_POST['action'] === 'reset_cloudflare_settings') {
        // Kiểm tra nonce để đảm bảo yêu cầu hợp lệ
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'cloudflare_reset_settings')) {
            wp_die('Yêu cầu không hợp lệ.');
        }

        // Thực hiện reset cài đặt về mặc định
        $this->reset_to_default_settings();

        // Chuyển hướng người dùng trở lại trang cài đặt với tham số reset
        wp_redirect(add_query_arg('settings-reset', 'true', admin_url('tools.php?page=cloudflare-cache-settings')));
        exit;
    }
}

	// Hàm reset cài đặt về mặc định
private function reset_to_default_settings() {
    $default_values = [
        'email'            => '',
        'api_key'          => '',
        'zone_id'          => '',
        'sitemap_url'      => $this->detect_sitemap_url(), // Tự động phát hiện sitemap
        'custom_urls'      => '',
        'enable_logging'   => '0',
        'auto_preload'     => '0',
        'auto_preload_time'=> '02:00', // Giá trị mặc định là 02:00
        'last_preload_run' => '',     // Xóa lịch sử chạy cron
    ];

    // Cập nhật cài đặt về mặc định
    $update_result = update_option('cloudflare_cache_options', $default_values);

    // Ghi log kết quả cập nhật
    if ($update_result) {
        $this->log_message("Cài đặt đã được reset về mặc định: " . print_r($default_values, true));
    } else {
        $this->log_message("Không thể cập nhật cài đặt về mặc định.");
    }

    // Xóa cache để tránh dữ liệu cũ
    delete_transient('cloudflare_cache_options');

    // Thêm thông báo reset thành công
    add_settings_error(
        'cloudflare_cache_settings',
        'cloudflare_cache_settings_success',
        'Đã đặt lại cài đặt về mặc định!',
        'updated'
    );
}


}

// Khởi tạo plugin
new Cloudflare_Auto_Cache_Purge_And_Preload();
