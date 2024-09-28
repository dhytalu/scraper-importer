<?php
/**
 * Plugin Name: Scraper Importer
 * Description: Plugin untuk mengimpor data artikel dari sitemap XML ke Wordpress melalui AJAX.
 * Version: 1.0
 * Author: Kadican
 * Author URI: https://github.com/dhytalu_
 * Text Domain: news-scrapper
 * Domain Path: /languages 
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package news-scrapper
 * @version 1.0
 * @author Kadican
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function get_image_urls_from_article($article_response) {
    // Cek apakah response tidak error
    if (!is_wp_error($article_response)) {
        // Ambil isi body dari response
        $article_body = wp_remote_retrieve_body($article_response);
        
        // Buat instance DOMDocument
        $dom = new DOMDocument();
        
        // Supaya tidak ada warning dari HTML5
        libxml_use_internal_errors(true);
        
        // Load HTML
        $dom->loadHTML($article_body);
        libxml_clear_errors();
    
        // Buat instance DOMXPath untuk query
        $xpath = new DOMXPath($dom);
    
        // Ambil semua elemen <img> di dalam <div class="photo__img">
        $images = $xpath->query("//div[contains(@class, 'photo__img')]//img");
    
        // Array untuk menyimpan URL gambar
        $image_data = [];
    
        // Loop melalui elemen gambar dan ambil URL dan alt
        foreach ($images as $image) {
            $img_url = $image->getAttribute('src');
            $img_alt = $image->getAttribute('alt');
            
            // Hapus spasi di awal atau akhir URL
            // $img_url = trim($img_url);
            
            // Bersihkan dan encode URL untuk menghindari spasi atau karakter ilegal
            $img_url = filter_var($img_url, FILTER_SANITIZE_URL);
            $img_url = str_replace(' ', '', $img_url); // Encode spasi sebagai
            
            $image_data[] = [
                'url' => $img_url,
                'alt' => $img_alt
            ]; // Tambahkan URL gambar dan alt ke array
        }

        // Kembalikan array yang berisi URL dan alt gambar
        return $image_data;
    }
    
    return null; // Jika error, kembalikan null
}

/**
 * Fungsi untuk melakukan scraping data dari sitemap XML
 */
// Menambahkan action untuk menangani request AJAX
add_action('wp_ajax_fetch_scrapper_articles', 'fetch_scrapper_articles');
// add_action('wp_ajax_nopriv_fetch_scrapper_articles', 'fetch_scrapper_articles'); // Agar bisa digunakan oleh user yang belum login (opsional)
function fetch_scrapper_articles() {
    // Cek nonce untuk keamanan
    check_ajax_referer('scrapper_nonce', 'security');

    // Ambil data dari request POST
    $formData = isset($_POST['formData']) ? $_POST['formData'] : '';

    if (empty($formData['target'])) {
        wp_send_json_error(['message' => 'URL target tidak ditemukan']);
        return;
    }

    $feed_url = $formData['target']; // URL dari form
    $status = $formData['status']; // Status post (publish/draft)

    // Inisialisasi cURL untuk mengambil data dari URL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $feed_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Mengembalikan hasil sebagai string
    $response = curl_exec($ch);
    curl_close($ch);

    // Jika ada error saat mengambil data
    if ($response === false) {
        wp_send_json_error(['message' => 'Gagal mengambil data dari URL.']);
        return;
    }

    // Parse XML response menggunakan SimpleXML
    $xml = simplexml_load_string($response);

    // Jika XML gagal diparse
    if ($xml === false) {
        wp_send_json_error(['message' => 'Gagal memproses XML.']);
        return;
    }

    // Inisialisasi array untuk menyimpan hasil scraping
    // Periksa susunan XML dari elemen <url> (dapat anda sesuaikan sesuai kebutuhan)
    $data = [];
    $items = $xml->url;

    // Iterasi setiap elemen <url> dan ambil data yang diperlukan
    foreach ($items as $item) {
        $data[] = [
            'loc' => (string) $item->loc, // URL artikel
            'publication_date' => (string) $item->children('news', true)->news->children('news', true)->publication_date, // Tanggal publikasi
            'title' => (string) $item->children('news', true)->news->children('news', true)->title, // Judul artikel
            'status'    => $status 
        ];
    }

    // Kirim hasil kembali ke client-side
    if (!empty($data)) {
        wp_send_json_success(['data' => $data, 'message' => 'Scraping selesai.']);
    } else {
        wp_send_json_error(['message' => 'Tidak ada data yang ditemukan.']);
    }

    // Kirim hasil kembali ke client-side
    wp_send_json_success(['data' => $data, 'message' => 'Scraping selesai.']);

    die();
}

// Fungsi untuk ekstrak tag <article> menjadi content dari HTML dan menghilangkan tag yang tidak diperlukan
function fetch_article_tag_content($html) {
    // Gunakan DOMDocument untuk mengubah HTML menjadi DOM
    $dom = new DOMDocument();
    // Cek error karena format HTML yang salah
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    // Temukan tag <artikel> pertama
    $article = $dom->getElementsByTagName('article');
    
    if ($article->length > 0) {
        $article_content = $article->item(0);

        // Hapus tag yang tidak diinginkan: <div>, <a>, <center>, <script>
        remove_unwanted_tags($article_content, ['div', 'a', 'center', 'script']);

        // Dapatkan HTML bagian dalam dari tag <article> yang telah dibersihkan
        $clean_content = dom_inner_html($article_content);

        // Hapus teks "Baca Juga" menggunakan regex
        $clean_content = preg_replace('/Baca Juga:.*?<br\s*\/?>/i', '', $clean_content);

        // Hapus <strong>Baca Juga:</strong>
        $clean_content = preg_replace('/<strong>\s*Baca Juga:\s*<\/strong>/i', '', $clean_content);

         // Hapus HTML comments seperti <!--img1-->
        $clean_content = preg_replace('/<!--.*?-->/s', '', $clean_content);

        return $clean_content;
    }

    return 'No <article> tag found';
}

// Fungsi pembantu untuk menghapus tag yang tidak diinginkan
function remove_unwanted_tags($element, $tags) {
    foreach ($tags as $tag) {
        $nodes = $element->getElementsByTagName($tag);
        // Karena getElementsByTagName aktif, lakukan iterasi mundur untuk menghindari konflik
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }
}

// Fungsi pembantu untuk mendapatkan HTML bagian dalam dari elemen DOM
function dom_inner_html(DOMNode $element) {
    $innerHTML = '';
    foreach ($element->childNodes as $child) {
        $innerHTML .= $element->ownerDocument->saveHTML($child);
    }
    return $innerHTML;
}

// Fungsi untuk menetapkan featured image dari URL
function set_featured_image_from_url($post_id, $image_url, $image_caption) {
    // Dapatkan direktori upload
    $upload_dir = wp_upload_dir();
    // Ambil data gambar dari URL
    $image_data = file_get_contents($image_url);
    // Dapatkan nama file dari URL
    $filename = basename($image_url);
    // Tentukan path untuk menyimpan file
    $file = $upload_dir['path'] . '/' . $filename;

    // Simpan file gambar ke dalam direktori upload
    file_put_contents($file, $image_data);

    // Cek tipe file (mime type)
    $wp_filetype = wp_check_filetype($filename, null);

    // Siapkan attachment array dengan tipe mime, judul, dan status
    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_excerpt'   => $image_caption // Set caption di sini
    );

    // Masukkan attachment ke dalam post
    $attach_id = wp_insert_attachment($attachment, $file, $post_id);

    // Sertakan file yang diperlukan untuk proses metadata
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Buat metadata untuk attachment dan update database
    $attach_data = wp_generate_attachment_metadata($attach_id, $file);
    wp_update_attachment_metadata($attach_id, $attach_data);

    // Set attachment sebagai featured image untuk post yang diberikan
    set_post_thumbnail($post_id, $attach_id);

    // Return ID attachment
    return $attach_id;
}

/**
 * Enqueue script dan pass nonce ke JavaScript
 */
function enqueue_scrapper_scripts() {
    // Enqueue jQuery (jika belum di-load)
    wp_enqueue_script('jquery');

    // Localize script untuk mengirim data URL AJAX dan nonce ke JavaScript
    wp_localize_script('jquery', 'scrapper_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('scrapper_nonce')
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_scrapper_scripts');

/**
 * Menambahkan menu halaman admin untuk plugin
 */
function add_scraper_importer_menu() {
    add_menu_page(
        'Scraper Importer', // Page title
        'Scraper Importer', // Menu title
        'manage_options',   // Capability
        'scraper-importer', // Menu slug
        'scraper_importer_page', // Callback function
        'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-database-fill-down" viewBox="0 0 16 16"><path d="M12.5 9a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7m.354 5.854 1.5-1.5a.5.5 0 0 0-.708-.708l-.646.647V10.5a.5.5 0 0 0-1 0v2.793l-.646-.647a.5.5 0 0 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0M8 1c-1.573 0-3.022.289-4.096.777C2.875 2.245 2 2.993 2 4s.875 1.755 1.904 2.223C4.978 6.711 6.427 7 8 7s3.022-.289 4.096-.777C13.125 5.755 14 5.007 14 4s-.875-1.755-1.904-2.223C11.022 1.289 9.573 1 8 1"/><path d="M2 7v-.839c.457.432 1.004.751 1.49.972C4.722 7.693 6.318 8 8 8s3.278-.307 4.51-.867c.486-.22 1.033-.54 1.49-.972V7c0 .424-.155.802-.411 1.133a4.51 4.51 0 0 0-4.815 1.843A12 12 0 0 1 8 10c-1.573 0-3.022-.289-4.096-.777C2.875 8.755 2 8.007 2 7m6.257 3.998L8 11c-1.682 0-3.278-.307-4.51-.867-.486-.22-1.033-.54-1.49-.972V10c0 1.007.875 1.755 1.904 2.223C4.978 12.711 6.427 13 8 13h.027a4.55 4.55 0 0 1 .23-2.002m-.002 3L8 14c-1.682 0-3.278-.307-4.51-.867-.486-.22-1.033-.54-1.49-.972V13c0 1.007.875 1.755 1.904 2.223C4.978 15.711 6.427 16 8 16c.536 0 1.058-.034 1.555-.097a4.5 4.5 0 0 1-1.3-1.905"/></svg>'), // Ikon SVG atau URL ikon
        6 // Posisi menu (opsional)
    );
}
add_action('admin_menu', 'add_scraper_importer_menu');

// Function untuk cek apakah slug category sudah ada atau belum
function set_post_term($post_id, $term_slug, $taxonomy) {
    // Periksa apakah term sudah ada berdasarkan slug di taksonomi yang ditentukan
    $term = term_exists($term_slug, $taxonomy);

    // Jika term belum ada, buat term baru
    if ($term === 0 || $term === null) {
        // Buat term baru dengan slug yang sama
        $new_term = wp_insert_term($term_slug, $taxonomy, array(
            'slug' => $term_slug,
        ));

        // Jika terjadi error saat membuat term, kembalikan pesan error
        if (is_wp_error($new_term)) {
            return $new_term;
        }

        // Set term ID ke variabel
        $term_id = $new_term['term_id'];
    } else {
        // Jika term sudah ada, gunakan ID term yang ada
        // $term bisa mengembalikan term_id atau array dengan term_id dan term_taxonomy_id
        $term_id = is_array($term) ? $term['term_id'] : $term;
    }

    // Set term ke post menggunakan term_id yang valid
    $result = wp_set_object_terms($post_id, (int) $term_id, $taxonomy);

    // Cek apakah ada error saat menyetel term ke post
    if (is_wp_error($result)) {
        return $result;
    }

    return true; // Kembalikan true jika berhasil
}

add_action('wp_ajax_insert_batch_articles', 'insert_batch_articles');
function insert_batch_articles() {
    check_ajax_referer('scrapper_nonce', 'security');

    // Ambil batch artikel yang dikirimkan dari AJAX
    $articles = isset($_POST['articles']) ? $_POST['articles'] : [];

    if (empty($articles)) {
        wp_send_json_error(['message' => 'Tidak ada artikel untuk diinsert.']);
    }

    $successCount = 0;

    foreach ($articles as $article) {
        $title  = sanitize_text_field($article['title']);
        $status = sanitize_text_field($article['status']);
        $loc    = esc_url_raw($article['loc']);
        $publication_date = sanitize_text_field($article['publication_date']);

        // Mengambil bagian path dari URL
        $path = parse_url($loc, PHP_URL_PATH);

        // Memecah path berdasarkan '/'
        $pathSegments = explode('/', trim($path, '/'));

        // Mengambil nilai yang diinginkan (index ke-1 karena index ke-0 adalah 'daerah')
        $category = $pathSegments[0];

        // Fetch the content of the article by visiting the URL
        $article_url = (string) $loc;
        $article_response = wp_remote_get($article_url);

        // Check if the response is valid
        if (!is_wp_error($article_response)) {
            $article_body = wp_remote_retrieve_body($article_response);
            // You may need to customize this based on the structure of the external site
            $content = fetch_article_tag_content($article_body);
        } else {
            $content = '';
        }

        // Cek apakah post sudah ada atau belum
        $existing_post = get_page_by_title($title, OBJECT, 'post');
        if ($existing_post) {
            // Jika post sudah ada, tampilkan pesan
            echo '<p>Gagal import! Post dengan judul "' . esc_html($title) . '" sudah ada.</p>';
        } else {
            // Data post yang akan diinsert
            $post_data = array(
                'post_title'    => $title,
                'post_content'  => $content, // Jika ingin menyimpan URL sebagai konten
                'post_status'   => $status, // Atur status sesuai yang diperlukan
                'post_date'     => $publication_date,
                'post_author'   => get_current_user_id(),
                'post_type'     => 'post',
                'meta_input'    => ['thumbnail'   => esc_url($thumbnail)],
            );

            // Insert post ke WordPress
            $post_id = wp_insert_post($post_data);
            if ($post_id) {
                $successCount++;
            }
            // set category
            // echo $category;
            if($category){
                set_post_term($post_id, $category, 'category');
            }

            $img_urls = get_image_urls_from_article($article_response);
            foreach($img_urls as $data) {
                $img_url = $data['url'];
                $img_alt = $data['alt'];

                // Jika ada thumbnail, set sebagai featured image
                if (!empty($img_url)) {
                    set_featured_image_from_url($post_id, $img_url, $img_alt);
                }
            }
        }
    }

    wp_send_json_success(['message' => $successCount . ' artikel berhasil diinsert.']);
    
    die();
}

/**
 * Fungsi untuk menampilkan halaman plugin di admin
 */
function scraper_importer_page() {
    ?>
    <div class="wrap">
        <h1>Scraper Importer</h1>
        <form id="scraper-form">
            <label for="target-url">Masukkan URL Sitemap:</label>
            <input type="text" id="target-url" name="target-url" placeholder="https://example.com/sitemap.xml">
            <br><br>
            
            <label for="status">Pilih Status Publikasi:</label>
            <select id="status" name="status">
                <option value="publish">Publish</option>
                <option value="draft">Draft</option>
            </select>
            <br><br>

            <input type="button" id="scrape-button" value="Scrape" class="button button-primary">
        </form>
        
        <!-- Tempat untuk menampilkan progress -->
        <div id="progress"></div>
        
        <!-- Tempat untuk menampilkan hasil scraping -->
        <div id="result"></div>
    </div>

    <script>
jQuery(document).ready(function($) {
    // Ketika tombol "Scrape" diklik
    $('#scrape-button').click(function() {
        // Ambil nilai URL dan status dari form input
        var formData = {
            'target': $('#target-url').val(),
            'status': $('#status').val()
        };

        // Tampilkan progress saat scraping berjalan
        $('#progress').html('Sedang mengambil data...');

        // Kirim request AJAX untuk mendapatkan artikel
        $.ajax({
            url: scrapper_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fetch_scrapper_articles',
                formData: formData,
                security: scrapper_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#progress').html('<p>' + response.message + '</p>');
                    
                    // Ambil data artikel
                    if (Array.isArray(response.data.data)) {
                        var articles = response.data.data;
                        var totalItems = articles.length;
                        var batchSize = 5; // Proses 10 artikel per batch
                        var currentBatch = 0;

                        function insertBatch() {
                            // Ambil batch artikel
                            var start = currentBatch * batchSize;
                            var end = start + batchSize;
                            var batch = articles.slice(start, end);

                            // Kirim batch ke server untuk di-insert ke WordPress
                            $.ajax({
                                url: scrapper_vars.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'insert_batch_articles',
                                    articles: batch,
                                    security: scrapper_vars.nonce
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $('#progress').html('<p>Sedang memproses' +end +'/'+totalItems + ' </p>');

                                        // Lanjutkan ke batch berikutnya
                                        currentBatch++;
                                        if (currentBatch * batchSize < totalItems) {
                                            insertBatch(); // Proses batch berikutnya
                                        } else {
                                            $('#progress').html('<p>Semua artikel telah diimpor.</p>');
                                        }
                                    } else {
                                        $('#progress').html('<p>Error: ' + response.message + '</p>');
                                    }
                                },
                                error: function(jqXHR, textStatus, errorThrown) {
                                    $('#progress').html('<p>Error: ' + textStatus + ' - ' + errorThrown + '</p>');
                                }
                            });
                        }

                        // Mulai batch pertama
                        insertBatch();
                    } else {
                        $('#progress').html('<p>Data tidak valid.</p>');
                    }
                } else {
                    $('#progress').html('<p>' + response.message + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $('#progress').html('<p>Error: ' + textStatus + ' - ' + errorThrown + '</p>');
            }
        });
    });
});
    </script>
    <?php
}
