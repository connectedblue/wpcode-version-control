<?php
/**
 * Plugin Name: WPCode Version Control
 * Description: A version control system for WPCode snippets. Export snapshots, restore snippets, and auto-clean trash.
 * Version: 1.3
 * Author: Gemini
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class WPCode_Version_Control {

    private $upload_dir;
    private $option_key = 'wpcode_vc_index';
    private $trash_retention_days = 30; // Auto-delete after 30 days

    public function __construct() {
        $upload_info = wp_upload_dir();
        $this->upload_dir = $upload_info['basedir'] . '/wpcode-versions';

        // UI Hooks
        // CHANGE: Priority 20 ensures we run AFTER WPCode creates its menu, fixing the "Duplicate" glitch.
        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('wpcode_vc_daily_cleanup', [$this, 'do_daily_cleanup']);
    }

    public static function activate() {
        if (!wp_next_scheduled('wpcode_vc_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wpcode_vc_daily_cleanup');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('wpcode_vc_daily_cleanup');
    }

    public function do_daily_cleanup() {
        $versions = get_option($this->option_key, []);
        $modified = false;
        $cutoff = time() - ($this->trash_retention_days * DAY_IN_SECONDS);

        foreach ($versions as $id => $v) {
            if (isset($v['status']) && $v['status'] === 'trash') {
                $trashed_time = isset($v['trashed_at']) ? $v['trashed_at'] : time();
                if ($trashed_time < $cutoff) {
                    $file = $this->upload_dir . '/' . $v['filename'];
                    if (file_exists($file)) @unlink($file);
                    unset($versions[$id]);
                    $modified = true;
                }
            }
        }
        if ($modified) update_option($this->option_key, $versions);
    }

    public function add_admin_menu() {
        // CHANGE: Added position '2' as the last argument to insert it after "Add Snippet"
        add_submenu_page(
            'wpcode', 
            'Version Control', 
            'Version Control', 
            'manage_options', 
            'wpcode-versions', 
            [$this, 'render_admin_page'],
            2 
        );
    }

    public function render_admin_page() {
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';
        
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">WPCode Version Control</h1>';
        
        switch ($view) {
            case 'inspect':
                $this->render_inspect_view();
                break;
            case 'trash':
                $this->render_trash_view();
                break;
            default:
                $this->render_list_view();
                break;
        }
        echo '</div>';
        
        ?>
        <script>
        jQuery(document).ready(function($){
            $('.manage-column.column-cb input').click(function(){
                $(this).closest('table').find('tbody .check-column input').prop('checked', this.checked);
            });
        });
        </script>
        <?php
    }

    public function handle_form_actions() {
        if (!isset($_POST['wpcode_vc_action']) || !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('wpcode_vc_nonce');
        $action = sanitize_text_field($_POST['wpcode_vc_action']);
        $versions = get_option($this->option_key, []);

        // --- CREATE ---
        if ($action === 'create_version') {
            $desc = sanitize_text_field($_POST['version_description']);
            if (empty($desc)) $desc = 'Auto Backup ' . date('Y-m-d H:i');

            $args = ['post_type' => 'wpcode', 'posts_per_page' => -1, 'post_status' => ['publish', 'draft', 'private']];
            $snippets = get_posts($args);
            
            $export_data = [];
            foreach ($snippets as $snippet) {
                $meta = get_post_meta($snippet->ID);
                $export_data[] = [
                    'ID' => $snippet->ID,
                    'post_title' => $snippet->post_title,
                    'post_content' => $snippet->post_content,
                    'post_status' => $snippet->post_status,
                    'post_name' => $snippet->post_name,
                    'meta' => $meta
                ];
            }

            if (!file_exists($this->upload_dir)) {
                mkdir($this->upload_dir, 0755, true);
                file_put_contents($this->upload_dir . '/.htaccess', 'deny from all'); 
            }

            $filename = 'v_' . time() . '_' . substr(md5(rand()), 0, 6) . '.json';
            $file_path = $this->upload_dir . '/' . $filename;
            
            if (file_put_contents($file_path, json_encode($export_data))) {
                $new_id = uniqid();
                $versions[$new_id] = [
                    'id' => $new_id,
                    'timestamp' => time(),
                    'description' => $desc,
                    'filename' => $filename,
                    'count' => count($export_data),
                    'status' => 'active'
                ];
                update_option($this->option_key, $versions);
                $this->redirect_with_msg('Version created successfully.');
            } else {
                $this->redirect_with_msg('Error creating file. Check permissions.', 'error');
            }
        }

        // --- TRASH SINGLE ---
        if ($action === 'trash_version') {
            $vid = sanitize_text_field($_POST['version_id']);
            if (isset($versions[$vid])) {
                $versions[$vid]['status'] = 'trash';
                $versions[$vid]['trashed_at'] = time();
                update_option($this->option_key, $versions);
                $this->redirect_with_msg('Version moved to trash.');
            }
        }

        // --- TRASH BULK ---
        if ($action === 'bulk_trash_versions') {
            $ids = isset($_POST['bulk_versions']) ? $_POST['bulk_versions'] : [];
            if (!empty($ids)) {
                foreach ($ids as $vid) {
                    if (isset($versions[$vid])) {
                        $versions[$vid]['status'] = 'trash';
                        $versions[$vid]['trashed_at'] = time();
                    }
                }
                update_option($this->option_key, $versions);
                $this->redirect_with_msg(count($ids) . ' versions moved to trash.');
            } else {
                $this->redirect_with_msg('No versions selected.', 'error');
            }
        }

        // --- RESTORE RECORD ---
        if ($action === 'restore_version_record') {
            $vid = sanitize_text_field($_POST['version_id']);
            if (isset($versions[$vid])) {
                $versions[$vid]['status'] = 'active';
                unset($versions[$vid]['trashed_at']);
                update_option($this->option_key, $versions);
                $this->redirect_with_msg('Version restored from trash.');
            }
        }

        // --- DELETE PERMANENTLY ---
        if ($action === 'delete_permanently') {
            $vid = sanitize_text_field($_POST['version_id']);
            if (isset($versions[$vid])) {
                $file = $this->upload_dir . '/' . $versions[$vid]['filename'];
                if (file_exists($file)) @unlink($file);
                unset($versions[$vid]);
                update_option($this->option_key, $versions);
                $this->redirect_with_msg('Version deleted permanently.');
            }
        }

        // --- RESTORE SNIPPETS ---
        if ($action === 'bulk_restore_snippets') {
            $vid = sanitize_text_field($_POST['version_id']);
            $snippet_ids_to_restore = isset($_POST['snippets']) ? $_POST['snippets'] : [];

            if (empty($snippet_ids_to_restore) || !isset($versions[$vid])) return;

            $file_content = file_get_contents($this->upload_dir . '/' . $versions[$vid]['filename']);
            $json_data = json_decode($file_content, true);
            
            $source_snippets = [];
            foreach ($json_data as $item) {
                $source_snippets[$item['ID']] = $item;
            }

            $restored_count = 0;

            foreach ($snippet_ids_to_restore as $target_id) {
                if (!isset($source_snippets[$target_id])) continue;

                $data = $source_snippets[$target_id];
                $existing_post = get_post($target_id);

                $post_args = [
                    'post_title'    => $data['post_title'],
                    'post_content'  => $data['post_content'],
                    'post_status'   => $data['post_status'],
                    'post_name'     => $data['post_name'],
                    'post_type'     => 'wpcode',
                ];

                $final_post_id = 0;

                if ($existing_post && $existing_post->post_type === 'wpcode') {
                    $post_args['ID'] = $target_id;
                    $final_post_id = wp_update_post($post_args);
                } else {
                    $final_post_id = wp_insert_post($post_args);
                }

                if ($final_post_id && !is_wp_error($final_post_id)) {
                    $existing_meta = get_post_meta($final_post_id);
                    foreach($existing_meta as $key => $val) {
                         delete_post_meta($final_post_id, $key);
                    }
                    foreach ($data['meta'] as $m_key => $m_vals) {
                        foreach ($m_vals as $m_val) {
                            add_post_meta($final_post_id, $m_key, $m_val);
                        }
                    }
                    $restored_count++;
                }
            }

            $this->redirect_with_msg("$restored_count snippets processed successfully.", 'success', 'inspect&version_id='.$vid);
        }
    }

    private function render_list_view() {
        $versions = get_option($this->option_key, []);
        
        echo '<div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-bottom:20px; display:flex; gap:10px; align-items:center;">';
        echo '<h3 style="margin:0;">Create New Version</h3>';
        echo '<form method="post" style="display:flex; gap:10px; flex-grow:1;">';
        wp_nonce_field('wpcode_vc_nonce');
        echo '<input type="hidden" name="wpcode_vc_action" value="create_version">';
        echo '<input type="text" name="version_description" placeholder="e.g. Before update to v2.0" style="flex-grow:1;" required>';
        echo '<button type="submit" class="button button-primary">Create Snapshot</button>';
        echo '</form>';
        echo '</div>';

        echo '<ul class="subsubsub">';
        echo '<li class="all"><a href="?page=wpcode-versions" class="current">All Versions</a> |</li>';
        echo '<li class="trash"><a href="?page=wpcode-versions&view=trash">Trash</a></li>';
        echo '</ul>';

        echo '<form method="post">';
        wp_nonce_field('wpcode_vc_nonce');
        echo '<input type="hidden" name="wpcode_vc_action" value="bulk_trash_versions">';

        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<input type="submit" value="Move Selected to Trash" class="button action" onclick="return confirm(\'Move selected items to trash?\');">';
        echo '</div>';
        echo '</div>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><td id="cb" class="manage-column column-cb check-column"><input type="checkbox"></td><th>Date</th><th>Description</th><th>Snippets</th><th>Actions</th></tr></thead>';
        echo '<tbody>';
        
        $found = false;
        uasort($versions, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });

        foreach ($versions as $v) {
            if ($v['status'] === 'trash') continue;
            $found = true;
            $date_format = date('Y-m-d H:i', $v['timestamp']);
            $inspect_url = admin_url('admin.php?page=wpcode-versions&view=inspect&version_id=' . $v['id']);
            
            echo '<tr>';
            echo "<th scope='row' class='check-column'><input type='checkbox' name='bulk_versions[]' value='{$v['id']}'></th>";
            echo "<td><strong>$date_format</strong></td>";
            echo "<td>{$v['description']}</td>";
            echo "<td>{$v['count']}</td>";
            echo '<td>';
            echo "<a href='$inspect_url' class='button button-secondary'>Inspect & Restore</a> ";
            echo "<button type='submit' name='bulk_versions[]' value='{$v['id']}' class='button-link-delete' style='color:#a00; border:none; background:none; cursor:pointer; text-decoration:underline;'>Delete</button>";
            echo '</td>';
            echo '</tr>';
        }

        if (!$found) echo '<tr><td colspan="5">No active versions found.</td></tr>';
        echo '</tbody></table>';
        echo '</form>';
    }

    private function render_trash_view() {
        $versions = get_option($this->option_key, []);
        echo '<p><a href="?page=wpcode-versions">&larr; Back to Active Versions</a></p>';
        echo '<div class="notice notice-info inline"><p>Items in Trash are automatically permanently deleted after 30 days.</p></div>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Date Created</th><th>Date Trashed</th><th>Description</th><th>Actions</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($versions as $v) {
            if ($v['status'] !== 'trash') continue;
            
            $trashed_at = isset($v['trashed_at']) ? $v['trashed_at'] : time();
            $days_left = 30 - floor((time() - $trashed_at) / DAY_IN_SECONDS);
            if ($days_left < 0) $days_left = 0;
            
            echo '<tr>';
            echo "<td>" . date('Y-m-d H:i', $v['timestamp']) . "</td>";
            echo "<td>" . date('Y-m-d H:i', $trashed_at) . " <span style='color:#666'>($days_left days left)</span></td>";
            echo "<td>{$v['description']}</td>";
            echo '<td>';
            
            echo "<form method='post' style='display:inline-block; margin-right:5px;'>";
            wp_nonce_field('wpcode_vc_nonce');
            echo "<input type='hidden' name='wpcode_vc_action' value='restore_version_record'>";
            echo "<input type='hidden' name='version_id' value='{$v['id']}'>";
            echo "<button type='submit' class='button button-small'>Restore</button>";
            echo "</form>";

            echo "<form method='post' style='display:inline-block;' onsubmit='return confirm(\"Delete permanently? File will be removed.\");'>";
            wp_nonce_field('wpcode_vc_nonce');
            echo "<input type='hidden' name='wpcode_vc_action' value='delete_permanently'>";
            echo "<input type='hidden' name='version_id' value='{$v['id']}'>";
            echo "<button type='submit' class='button button-small button-link-delete'>Delete Permanently</button>";
            echo "</form>";
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_inspect_view() {
        $vid = isset($_GET['version_id']) ? sanitize_text_field($_GET['version_id']) : '';
        $versions = get_option($this->option_key, []);
        
        if (!isset($versions[$vid])) {
            echo '<div class="notice notice-error"><p>Version not found.</p></div>';
            return;
        }

        $v = $versions[$vid];
        $file_path = $this->upload_dir . '/' . $v['filename'];
        if (!file_exists($file_path)) {
            echo '<div class="notice notice-error"><p>File missing from disk.</p></div>';
            return;
        }

        $json_data = json_decode(file_get_contents($file_path), true);

        echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
        echo '<h2>Inspect: ' . esc_html($v['description']) . '</h2>';
        echo '<a href="?page=wpcode-versions" class="button">Back to Versions</a>';
        echo '</div>';

        echo '<form method="post">';
        wp_nonce_field('wpcode_vc_nonce');
        echo '<input type="hidden" name="wpcode_vc_action" value="bulk_restore_snippets">';
        echo '<input type="hidden" name="version_id" value="' . esc_attr($vid) . '">';

        echo '<div class="tablenav top"><div class="alignleft actions"><button type="submit" class="button button-primary">Restore Selected</button></div></div>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><td class="manage-column column-cb check-column"><input type="checkbox"></td><th>ID</th><th>Title</th><th>Type</th><th>Status Check</th></tr></thead>';
        echo '<tbody>';

        foreach ($json_data as $snippet) {
            $live_post = get_post($snippet['ID']);
            
            $status_html = '';
            $row_class = '';

            if (!$live_post || $live_post->post_type !== 'wpcode') {
                $status_html = '<span style="color:#d63638; font-weight:bold;">Deleted (Will create new)</span>';
                $row_class = 'background-color: #fff8f8;';
            } else {
                $live_content = $live_post->post_content;
                if (md5($live_content) === md5($snippet['post_content'])) {
                     $status_html = '<span style="color:#999;">Identical</span>';
                } else {
                     $status_html = '<span style="color:#0073aa; font-weight:bold;">Changed (Will overwrite)</span>';
                }
            }

            $type = isset($snippet['meta']['_wpcode_snippet_type']) ? $snippet['meta']['_wpcode_snippet_type'][0] : 'Snippet';

            echo "<tr style='$row_class'>";
            echo "<th scope='row' class='check-column'><input type='checkbox' name='snippets[]' value='{$snippet['ID']}'></th>";
            echo "<td>{$snippet['ID']}</td>";
            echo "<td><strong>" . esc_html($snippet['post_title']) . "</strong></td>";
            echo "<td>" . esc_html(strtoupper($type)) . "</td>";
            echo "<td>$status_html</td>";
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</form>';
    }

    private function redirect_with_msg($msg, $type = 'success', $page = '') {
        $url = 'admin.php?page=wpcode-versions';
        if($page) $url .= '&view=' . $page;
        set_transient('wpcode_vc_msg', ['text' => $msg, 'type' => $type], 45);
        wp_redirect(admin_url($url));
        exit;
    }

    public function admin_notices() {
        if ($msg = get_transient('wpcode_vc_msg')) {
            $class = ($msg['type'] === 'error') ? 'notice-error' : 'notice-success';
            echo "<div class='notice $class is-dismissible'><p>{$msg['text']}</p></div>";
            delete_transient('wpcode_vc_msg');
        }
    }
}

register_activation_hook(__FILE__, ['WPCode_Version_Control', 'activate']);
register_deactivation_hook(__FILE__, ['WPCode_Version_Control', 'deactivate']);

new WPCode_Version_Control();