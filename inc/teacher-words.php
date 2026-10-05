<?php
/**
 * The teacher's own pages in Tutor LMS still show some English. These are put into Vietnamese here.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'gettext', function ( $text, $original, $domain ) {
	if ( 'tutor' !== $domain && 'tutor-pro' !== $domain ) {
		return $text;
	}
	static $words = array(
		'Total Earnings'         => 'Tổng thu',
		'Total Courses'          => 'Số khóa học',
		'Total Students'         => 'Số học viên',
		'Avg. Rating'            => 'Điểm đánh giá',
		'Course Completion Rate' => 'Học viên học tới đâu',
		'Enrolled'               => 'Đã ghi danh',
		'Completed'              => 'Đã học xong',
		'In Progress'            => 'Đang học',
		'Inactive'               => 'Chưa học',
		'Cancelled'              => 'Đã hủy',
		'Top Performing Courses' => 'Khóa học nổi bật',
		'By: Revenue'            => 'Theo doanh thu',
		'By: Students'           => 'Theo số học viên',
		'Revenue'                => 'Doanh thu',
		'Students'               => 'Học viên',
	);
	return isset( $words[ $original ] ) ? $words[ $original ] : $text;
}, 20, 3 );
