<?php
/**
* Plugin Name: Gemini Quiz Generator
* Description: Generates an interactive quiz from page content using the Gemini API. Use the [gemini_quiz] shortcode.
* Version: 1.6.4
* Author: Your Name
* License: GPL-2.0-or-later
* Text Domain: gemini-quiz-generator
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GEMINI_QUIZ_VERSION', '1.6.4');

/**
 * Initialize the plugin
 */
add_action('init', 'gemini_quiz_init');

function gemini_quiz_init() {
    add_action('rest_api_init', 'gemini_quiz_register_rest_routes');
    add_action('wp_enqueue_scripts', 'gemini_quiz_enqueue_assets');
}

/**
 * Register REST API routes
 */
function gemini_quiz_register_rest_routes() {
    register_rest_route('quiz-generator/v1', '/generate', array(
        'methods' => 'POST',
        'callback' => 'gemini_quiz_generate_quiz',
        'permission_callback' => '__return_true',
        'args' => array(
            'article' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'postId' => array(
                'required' => false,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
}

/**
 * Generate quiz using Gemini API
 */
function gemini_quiz_generate_quiz($request) {
    $article = $request->get_param('article');
    $post_id = $request->get_param('postId');

    // Get Gemini API key
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : get_option('gemini_api_key');

    if (empty($api_key)) {
        return new WP_Error('missing_api_key', 'Gemini API key is not configured', array('status' => 500));
    }

    if (empty($article)) {
        return new WP_Error('empty_article', 'Article content cannot be empty', array('status' => 400));
    }

    // Call Gemini API
    $quiz_data = gemini_quiz_call_api($article, $api_key);

    if (is_wp_error($quiz_data)) {
        return $quiz_data;
    }

    // Get related post if postId is provided
    $related_post = null;
    if ($post_id) {
        $post = get_post($post_id);
        if ($post) {
            $related_post = array(
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
            );
        }
    }

    return new WP_REST_Response(array(
        'quiz' => $quiz_data,
        'relatedPost' => $related_post,
    ), 200);
}

/**
 * Call Gemini API to generate quiz
 */
function gemini_quiz_call_api($article, $api_key) {
    $prompt = "Based on the following article, create a quiz with 5 multiple-choice questions. Each question should have 4 options with only one correct answer. The entire response must be a valid JSON array, where each question object has: 'question' (string), 'options' (array of 4 strings), 'correctAnswer' (integer index 0-3), and 'explanation' (string explaining why the answer is correct).\n\nArticle:\n" . $article;

    $body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array('text' => $prompt)
                )
            )
        ),
        'generationConfig' => array(
            'temperature' => 0.7,
            'maxOutputTokens' => 2048,
            'response_mime_type' => 'application/json',
        )
    );

    $response = wp_remote_post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=" . $api_key, array(
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($body),
        'timeout' => 30,
    ));

    if (is_wp_error($response)) {
        return new WP_Error('api_error', 'Failed to connect to Gemini API: ' . $response->get_error_message(), array('status' => 500));
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($response_code !== 200) {
        return new WP_Error('api_error', 'Gemini API returned error: ' . $response_code, array('status' => 500));
    }

    $data = json_decode($response_body, true);

    if (!$data || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        return new WP_Error('invalid_response', 'Invalid response from Gemini API', array('status' => 500));
    }

    $quiz_text = $data['candidates'][0]['content']['parts'][0]['text'];

    // Directly decode the JSON response from the API
    $quiz_data = json_decode($quiz_text, true);

    if (!$quiz_data || !is_array($quiz_data)) {
        return new WP_Error('invalid_quiz_data', 'Failed to parse quiz data from API response. Response was: ' . $quiz_text, array('status' => 500));
    }

    return $quiz_data;
}


/**
 * Shortcode handler
 */
function gemini_quiz_shortcode_handler($atts) {
    global $post;
    $post_id = $post ? $post->ID : 0;

    // Get content title for display
    $content_title = 'this page';
    if ($post && $post->post_title) {
        $content_title = $post->post_title;
    }

    // The initial HTML structure for the quiz container
    $output = '
    <div class="gemini-quiz-container" data-post-id="' . esc_attr($post_id) . '" data-content-title="' . esc_attr($content_title) . '">
        <div class="quiz-card">
            <div class="quiz-header">
                <h2>🎯 Quiz Generator</h2>
                <p>Test your knowledge of ' . esc_html($content_title) . '</p>
            </div>
            <div style="text-align: center; padding: 0 10px;">
                <p style="margin: 20px 0; font-size: clamp(15px, 3.5vw, 18px); color: #333; line-height: 1.6;">
                    Ready to test your understanding? I\'ll create a personalized quiz based on the content of this page.
                </p>
                <button class="gemini-quiz-start-button">
                    Generate Quiz
                </button>
            </div>
        </div>
    </div>';

    return $output;
}
add_shortcode('gemini_quiz', 'gemini_quiz_shortcode_handler');

/**
 * Enqueue styles and scripts
 */
function gemini_quiz_enqueue_assets() {
    if (is_singular() && has_shortcode(get_the_content(), 'gemini_quiz')) {
        wp_enqueue_style(
            'gemini-quiz-style',
            plugin_dir_url(__FILE__) . 'gemini-quiz.css',
            array(),
            GEMINI_QUIZ_VERSION
        );

        wp_enqueue_script(
            'gemini-quiz-script',
            plugin_dir_url(__FILE__) . 'gemini-quiz.js',
            array(),
            GEMINI_QUIZ_VERSION,
            true
        );
    }
}

/**
 * Add admin menu for plugin settings
 */
add_action('admin_menu', 'gemini_quiz_admin_menu');

function gemini_quiz_admin_menu() {
    add_options_page(
        'Gemini Quiz Generator Settings',
        'Gemini Quiz',
        'manage_options',
        'gemini-quiz-settings',
        'gemini_quiz_settings_page'
    );
}

/**
 * Settings page
 */
function gemini_quiz_settings_page() {
    if (isset($_POST['submit'])) {
        check_admin_referer('gemini_quiz_settings');
        $api_key = sanitize_text_field($_POST['gemini_api_key']);
        update_option('gemini_api_key', $api_key);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    $api_key = get_option('gemini_api_key', '');
    $config_key_exists = defined('GEMINI_API_KEY');
    ?>
    <div class="wrap">
        <h1>Gemini Quiz Generator Settings</h1>

        <?php if ($config_key_exists): ?>
            <div class="notice notice-info">
                <p><strong>API Key Status:</strong> ✅ Using API key from wp-config.php</p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('gemini_quiz_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Gemini API Key</th>
                    <td>
                        <input type="text" name="gemini_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" <?php echo $config_key_exists ? 'disabled' : ''; ?> />
                        <p class="description">
                            <?php if ($config_key_exists): ?>
                                API key is configured in wp-config.php.
                            <?php else: ?>
                                Enter your Google Gemini API key from <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php if (!$config_key_exists): ?>
                <?php submit_button(); ?>
            <?php endif; ?>
        </form>

        <div class="card">
            <h2>Usage</h2>
            <p>Add the shortcode <code>[gemini_quiz]</code> to any page or post where you want the quiz to appear.</p>

            <h3>Theme Integration</h3>
            <p>The quiz is designed to match your site's theme color (#de200b) and is fully mobile-responsive.</p>

            <h3>Status Check</h3>
            <ul>
                <li>Plugin Version: <?php echo GEMINI_QUIZ_VERSION; ?></li>
                <li>WordPress Version: <?php echo get_bloginfo('version'); ?></li>
                <li>PHP Version: <?php echo PHP_VERSION; ?></li>
                <li>API Key: <?php echo $config_key_exists || !empty($api_key) ? '✅ Configured' : '❌ Not configured'; ?></li>
                <li>Theme Color: #de200b ✅</li>
                <li>Mobile Responsive: ✅</li>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'gemini_quiz_activate');

function gemini_quiz_activate() {
    flush_rewrite_rules();
}

/**
 * Plugin deactivation hook
 */
register_deactivation_hook(__FILE__, 'gemini_quiz_deactivate');

function gemini_quiz_deactivate() {
    flush_rewrite_rules();
}