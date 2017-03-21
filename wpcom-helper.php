<?php

add_filter( 'wpcom_vip_passthrough_cron_to_jobs', function( $whitelist ) {
	$whitelist[] = 'ctcu_deferred_update_terms_count';
	return $whitelist;
} );
