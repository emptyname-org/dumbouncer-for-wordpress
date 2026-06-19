<?php
/**
 * Settings screen under Settings -> Dumbouncer. Recipient, subject, difficulty,
 * and one toggle per integration. Everything has a working default, so the
 * plugin protects comments and contact forms the moment it is activated.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dumbouncer_Settings {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_filter(
            'plugin_action_links_' . plugin_basename(DUMBOUNCER_FILE),
            array(__CLASS__, 'action_link')
        );
    }

    public static function action_link($links) {
        $url = admin_url('options-general.php?page=dumbouncer');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'dumbouncer') . '</a>');
        return $links;
    }

    public static function menu() {
        add_options_page(
            'Dumbouncer',
            'Dumbouncer',
            'manage_options',
            'dumbouncer',
            array(__CLASS__, 'render')
        );
    }

    public static function register() {
        $opts = array(
            'dumbouncer_recipient'    => array('sanitize_email', ''),
            'dumbouncer_subject'      => array('sanitize_text_field', '[contact] '),
            'dumbouncer_bits'         => array('absint', 20),
            'dumbouncer_int_comments' => array(array(__CLASS__, 'bool'), '1'),
            'dumbouncer_int_cf7'      => array(array(__CLASS__, 'bool'), '1'),
            'dumbouncer_int_wpforms'  => array(array(__CLASS__, 'bool'), '1'),
            'dumbouncer_int_login'    => array(array(__CLASS__, 'bool'), ''),
            'dumbouncer_int_register' => array(array(__CLASS__, 'bool'), ''),
        );
        foreach ($opts as $name => $spec) {
            register_setting('dumbouncer', $name, array(
                'sanitize_callback' => $spec[0],
                'default'           => $spec[1],
            ));
        }
    }

    public static function bool($v) {
        return $v ? '1' : '';
    }

    private static function checkbox($name, $label, $default, $note = '') {
        $on = get_option($name, $default) === '1';
        echo '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($on, true, false) . '> '
           . esc_html($label) . '</label>';
        if ($note !== '') {
            echo ' <span class="description">' . esc_html($note) . '</span>';
        }
        echo '</p>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Dumbouncer</h1>
            <p><?php esc_html_e('A proof-of-work spam gate. Dumb bots bounce. Humans and agents solve the proof.', 'dumbouncer'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('dumbouncer'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dumbouncer_recipient"><?php esc_html_e('Contact recipient', 'dumbouncer'); ?></label></th>
                        <td>
                            <input type="email" id="dumbouncer_recipient" name="dumbouncer_recipient"
                                   value="<?php echo esc_attr(get_option('dumbouncer_recipient', '')); ?>"
                                   class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            <p class="description"><?php esc_html_e('Where the [dumbouncer_form] shortcode sends messages. Defaults to the site admin email.', 'dumbouncer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dumbouncer_subject"><?php esc_html_e('Subject prefix', 'dumbouncer'); ?></label></th>
                        <td><input type="text" id="dumbouncer_subject" name="dumbouncer_subject"
                                   value="<?php echo esc_attr(get_option('dumbouncer_subject', '[contact] ')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dumbouncer_bits"><?php esc_html_e('Difficulty (bits)', 'dumbouncer'); ?></label></th>
                        <td>
                            <input type="number" id="dumbouncer_bits" name="dumbouncer_bits" min="8" max="32"
                                   value="<?php echo esc_attr(get_option('dumbouncer_bits', 20)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Leading zero bits required (about 2^bits hashes). 20 is roughly 0.5-1s in a browser. Higher is harder for both spam and users.', 'dumbouncer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Protect', 'dumbouncer'); ?></th>
                        <td>
                            <?php
                            self::checkbox('dumbouncer_int_comments', __('Comment form', 'dumbouncer'), '1');
                            self::checkbox('dumbouncer_int_cf7', __('Contact Form 7', 'dumbouncer'), '1',
                                defined('WPCF7_VERSION') ? '' : __('(plugin not detected)', 'dumbouncer'));
                            self::checkbox('dumbouncer_int_wpforms', __('WPForms', 'dumbouncer'), '1',
                                function_exists('wpforms') ? '' : __('(plugin not detected)', 'dumbouncer'));
                            self::checkbox('dumbouncer_int_login', __('Login form', 'dumbouncer'), '',
                                __('Off by default. Can lock you out if browser JavaScript fails.', 'dumbouncer'));
                            self::checkbox('dumbouncer_int_register', __('Registration form', 'dumbouncer'), '');
                            ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2><?php esc_html_e('Contact form', 'dumbouncer'); ?></h2>
            <p><?php esc_html_e('Drop this shortcode into any page or post:', 'dumbouncer'); ?>
               <code>[dumbouncer_form title="Contact us"]</code></p>
        </div>
        <?php
    }
}
