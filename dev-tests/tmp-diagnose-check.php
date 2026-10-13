<?php
/**
 * Throwaway: run the real Haa_Settings::diagnose() on a fresh install, with
 * one module on and with all eight on. The dashboard shows this list, so it
 * must be empty in both states.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$slugs = array(
	'chatbot',
	'product-content',
	'article-writer',
	'autopilot',
	'leads',
	'discount',
	'comments',
	'semantic-search',
);

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 44 ) . $extra . "\n";
}

function with_modules( $enabled ) {
	global $slugs;
	$flags = array();
	foreach ( $slugs as $s ) {
		$flags[ $s ] = in_array( $s, $enabled, true ) ? 1 : 0;
	}
	update_option( Haa_Settings::MODULES_OPTION, $flags, 'yes' );

	$all      = Haa_Settings::get();
	$all['modules'] = $flags;
	update_option( HAAI_OPTION, $all, 'no' );
	Haa_Settings::flush_cache();

	return Haa_Settings::diagnose();
}

echo "=== diagnose() on a fresh install (chatbot only) ===\n";
$issues = with_modules( array( 'chatbot' ) );
ok( 'no issues with chatbot only', array() === $issues, count( $issues ) . ' issue(s)' );
if ( $issues ) {
	foreach ( array_slice( $issues, 0, 6 ) as $i ) {
		echo '       - ' . ( is_string( $i ) ? $i : json_encode( $i, JSON_UNESCAPED_UNICODE ) ) . "\n";
	}
}

// The v1 migrator writes the full 0/1 map, so the stored option really can hold
// off flags. enabled_modules() must still report only the live ones.
echo "\n=== migrator-shaped mirror (full 0/1 map stored) ===\n";
ok(
	'enabled_modules() drops off flags',
	array( 'chatbot' ) === array_keys( Haa_Settings::enabled_modules() ),
	implode( ', ', array_keys( Haa_Settings::enabled_modules() ) )
);
ok( 'is_module_enabled(chatbot)', true === Haa_Settings::is_module_enabled( 'chatbot' ) );
ok( 'is_module_enabled(leads)', false === Haa_Settings::is_module_enabled( 'leads' ) );
ok(
	'describe_all() lists every catalogued module',
	count( Haa_Modules::catalog() ) === count( Haa_Modules::describe_all() ),
	count( Haa_Modules::describe_all() ) . ' rows / ' . count( Haa_Modules::catalog() ) . ' catalogued'
);
// A slug present in catalog() but missing from catalog_meta() is exactly how a new
// module would break both the Modules screen and diagnose().
ok(
	'catalog() and catalog_meta() hold the same slugs',
	array_keys( Haa_Modules::catalog() ) === array_keys( Haa_Modules::catalog_meta() ),
	implode( ',', array_diff( array_keys( Haa_Modules::catalog() ), array_keys( Haa_Modules::catalog_meta() ) ) )
);
ok(
	'required_tables() excludes dead-module tables',
	0 === count( array_filter( Haa_DB::required_tables(), function ( $t ) {
		return false !== strpos( $t, 'leads' ) || false !== strpos( $t, 'coupons' );
	} ) )
);

echo "\n=== diagnose() with all 8 modules enabled ===\n";
$issues = with_modules( $slugs );
ok( 'no issues with all modules', array() === $issues, count( $issues ) . ' issue(s)' );
if ( $issues ) {
	foreach ( array_slice( $issues, 0, 6 ) as $i ) {
		echo '       - ' . ( is_string( $i ) ? $i : json_encode( $i, JSON_UNESCAPED_UNICODE ) ) . "\n";
	}
}

echo "\n=== mirror stays in sync after a module toggle (issue 2 regression) ===\n";
with_modules( array( 'chatbot', 'leads' ) );
$mirror = get_option( Haa_Settings::MODULES_OPTION );
$big    = get_option( HAAI_OPTION );
ok( 'mirror has leads on',        ! empty( $mirror['leads'] ) );
ok( 'big option section agrees',  ! empty( $big['modules']['leads'] ) );
ok( 'mirror has comments off',    empty( $mirror['comments'] ) );
ok( 'big section has comments off', empty( $big['modules']['comments'] ) );

echo "\n=== reset() / save_all() must not silently switch modules off ===\n";
Haa_Settings::save_all( Haa_Settings::get() );
$after = get_option( Haa_Settings::MODULES_OPTION );
ok( 'leads still on after save_all', ! empty( $after['leads'] ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
