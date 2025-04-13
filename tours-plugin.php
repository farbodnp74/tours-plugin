<?php
/**
 * Plugin Name: Tour Sync for Traveler Theme Extended
 * Description: Syncs tour data from an external API and updates/creates tour posts with additional meta fields.
 * Version: 1.3.2
 * Author: Farbod
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tour_Sync_Extended_Plugin {
    const API_URL   = 'http://94.182.62.3:3000/tours';
    const POST_TYPE = 'st_tours';

    public function __construct() {}

    public function fetch_and_update_tours( $page = 1 ) {
        $url = self::API_URL . '?limit=10&page=' . $page;
        $response = wp_remote_get( $url );

        if ( is_wp_error( $response ) ) {
            error_log( 'Error fetching data from API.' );
            return;
        }

        $body  = wp_remote_retrieve_body( $response );
        $tours = json_decode( $body, true );

        if ( empty( $tours ) ) {
            error_log( 'No tours found to sync.' );
            return;
        }

        foreach ( $tours as $tour ) {
            $this->update_or_create_tour( $tour );
        }

        error_log( "Processed 10 tours for page $page" );
        echo "Processed 10 tours for page $page";
        exit;
    }

    private function gregorian_to_jalali( $gy, $gm, $gd ) {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + floor(($gy2 + 3)/4) - floor(($gy2 + 99)/100) + floor(($gy2 + 399)/400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * floor($days / 12053));
        $days %= 12053;
        $jy += 4 * floor($days / 1461);
        $days %= 1461;
        if ( $days > 365 ) {
            $jy += floor(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $jm = ($days < 186) ? 1 + floor($days / 31) : 7 + floor(($days - 186)/30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return [$jy, $jm, $jd];
    }

    private function get_lat_long_by_country( $country ) {
        $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($country);
        $response = wp_remote_get($url);

        if ( is_wp_error($response) ) return false;

        $data = json_decode( wp_remote_retrieve_body($response), true );
        if ( isset($data[0]['lat'], $data[0]['lon']) ) {
            return ['lat' => $data[0]['lat'], 'lng' => $data[0]['lon']];
        }

        return false;
    }

    private function update_or_create_tour( $tour ) {
        global $wpdb;

        if ( ! isset($tour['tour_id'], $tour['name']) ) return;

        $existing_tour_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tour_id' AND meta_value = %s LIMIT 1",
            $tour['tour_id']
        ));

        $post_status = ( isset($tour['status']) && $tour['status'] === 'sold out' ) ? 'draft' : 'publish';
        $post_date   = current_time( 'mysql' );

        $trip_duration = trim($tour['trip_duration']);
        if ( preg_match('/^روز\s*(\d+)$/u', $trip_duration, $matches) ) {
            $trip_duration = $matches[1] . ' روز';
        }

        $start_date = substr($tour['date'], 0, 10);
        [$gy, $gm, $gd] = explode('-', $start_date);
        [$jy, $jm, $jd] = $this->gregorian_to_jalali((int)$gy, (int)$gm, (int)$gd);
        $shamsi_date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        $content = $this->generate_content($tour, $shamsi_date, $trip_duration);

        $post_data = [
            'post_title'   => sanitize_text_field($tour['name']),
            'post_type'    => self::POST_TYPE,
            'post_status'  => $post_status,
            'post_date'    => $post_date,
            'post_content' => $content
        ];

        $post_id = $existing_tour_id
            ? wp_update_post(array_merge($post_data, ['ID' => $existing_tour_id]))
            : wp_insert_post($post_data);

        if ( is_wp_error($post_id) ) return;

        $this->update_post_meta_data($post_id, $tour, $trip_duration, $content);
    }

    private function generate_content( $tour, $shamsi_date, $trip_duration ) {
        return '
        <p>...متن تبلیغاتی درباره خدمات...</p>
        <table style="width:100%;border-collapse:collapse;margin-top:30px;">
            <thead>
                <tr style="background-color:#4e6813;color:#fff;">
                    <th style="padding:15px;">عنوان</th>
                    <th style="padding:15px;">توضیحات</th>
                </tr>
            </thead>
            <tbody>
                <tr><td style="padding:12px;">عنوان پرواز</td><td style="padding:12px;">' . esc_html($tour['air_line_title']) . '</td></tr>
                <tr><td style="padding:12px;">کلاس هتل</td><td style="padding:12px;">' . esc_html($tour['hotel_class']) . ' ستاره</td></tr>
                <tr><td style="padding:12px;">مقصد</td><td style="padding:12px;">' . esc_html($tour['serialized_city']) . '</td></tr>
                <tr><td style="padding:12px;">تاریخ شروع سفر</td><td style="padding:12px;">' . $shamsi_date . '</td></tr>
                <tr><td style="padding:12px;">مدت زمان تور</td><td style="padding:12px;">' . $trip_duration . '</td></tr>
            </tbody>
        </table>';
    }

    private function update_post_meta_data( $post_id, $tour, $trip_duration, $content ) {
        $location_mapping = [
            'استانبول' => 17452,
            'دبی' => 16939,
            'مالدیو' => 16941,
            'تفلیس' => 16948,
            'آنتالیا' => 16951,
            'کوالالامپور' => 16953,
            'پاتایا' => 16955,
            'باکو' => 16961,
            'نایروبی' => 16971,
        ];

        $location_thumbnail = [
            'استانبول' => 15549,
            'دبی' => 16983,
            'مالدیو' => 16984,
            'تفلیس' => 16985,
            'آنتالیا' => 16986,
            'کوالالامپور' => 16987,
            'پاتایا' => 16988,
            'باکو' => 16989,
            'نایروبی' => 16990,
        ];

        $gallery_mapping = [
            'استانبول' => [15549, 16983, 17162],
            'دبی' => [16983, 17163, 17164],
            'مالدیو' => [16984, 17165, 17166],
            'تفلیس' => [16985, 17167, 17168],
            'آنتالیا' => [16986, 17169, 17170],
            'کوالالامپور' => [16987, 17171, 17172],
            'پاتایا' => [16988, 17173, 17174],
            'باکو' => [16989, 17175, 17176],
            'نایروبی' => [16990, 17177, 17178],
        ];

        update_post_meta($post_id, 'tour_id', $tour['tour_id']);
        update_post_meta($post_id, 'tour_price', $tour['price']);
        update_post_meta($post_id, 'base_price', $tour['price']);
        update_post_meta($post_id, 'tour_image', esc_url_raw($tour['image']));
        update_post_meta($post_id, 'tour_category', $tour['category']);
        update_post_meta($post_id, 'duration_day', $trip_duration);
        update_post_meta($post_id, 'tour_price_by', 'fixed');
        update_post_meta($post_id, 'st_custom_layout_new', 9);
        update_post_meta($post_id, 'rating', 5);
        update_post_meta($post_id, 'st_booking_option_type', 'enquire');

        $gallery = $gallery_mapping[$tour['category']] ?? [15549, 16983, 17162];
        update_post_meta($post_id, 'gallery', implode(',', $gallery));

        $default_meta = [
            'st_tour_external_booking'        => 'off',
            'hide_adult_in_booking_form'      => 'off',
            'hide_children_in_booking_form'   => 'off',
            'hide_infant_in_booking_form'     => 'off',
            'disable_adult_name'              => 'on',
            'disable_children_name'           => 'on',
            'disable_infant_name'             => 'on',
            'type_tour'                       => 'specific_date',
            'max_people'                      => 10,
            'calendar_check_in'               => 'j۱۴۰۴/j۱۲/j۲۸',
            'calendar_check_out'              => 'j۱۴۰۴/j۱۲/j۲۹',
            'calendar_base_price'             => $tour['price']
        ];

        foreach ( $default_meta as $key => $value ) {
            update_post_meta($post_id, $key, $value);
        }

        if ( isset($location_mapping[$tour['category']]) ) {
            update_post_meta($post_id, 'multi_location[]', '_' . $location_mapping[$tour['category']] . '_');
        }

        if ( isset($tour['category']) ) {
            update_post_meta($post_id, 'address', $tour['category']);
        }

        $thumbnail_id = $location_thumbnail[$tour['category']] ?? 16983;
        set_post_thumbnail($post_id, $thumbnail_id);

        $coordinates = $this->get_lat_long_by_country($tour['category']);
        if ( $coordinates ) {
            update_post_meta($post_id, 'st_google_map_lat', $coordinates['lat']);
            update_post_meta($post_id, 'st_google_map_lng', $coordinates['lng']);
            update_post_meta($post_id, 'st_google_map_zoom', '5');
        }

        update_post_meta($post_id, 'content', $content);
    }
}

new Tour_Sync_Extended_Plugin();

add_action('init', function() {
    if ( isset($_GET['tour_sync']) ) {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        (new Tour_Sync_Extended_Plugin())->fetch_and_update_tours($page);
        exit;
    }
});
