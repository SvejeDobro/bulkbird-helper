<?php
/**
 * Čita log mjernog mu-plugina sa standardnog ulaza i ispisuje medijane po oznaci (Markdown tablica).
 * Poziva ga bin/measure.sh report.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$runs = array();
while ( false !== ( $line = fgets( STDIN ) ) ) {
	$row = json_decode( $line, true );
	if ( ! is_array( $row ) || empty( $row['run'] ) ) {
		continue;
	}
	$runs[ $row['run'] ][] = $row;
}
ksort( $runs );

$median = static function ( array $values ): float {
	sort( $values );
	$n = count( $values );
	return 0 === $n ? 0.0 : ( 1 === $n % 2 ? (float) $values[ intdiv( $n, 2 ) ] : ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2 );
};

echo "| Mjerenje | n | status | upiti (min–max) | PHP datoteke (min–max) | datoteke plugina | PHP ms (medijan) |\n";
echo "|---|---|---|---|---|---|---|\n";
foreach ( $runs as $run => $rows ) {
	$queries = array_column( $rows, 'queries' );
	$files   = array_column( $rows, 'included_files' );
	$plugin  = array();
	foreach ( $rows as $row ) {
		foreach ( (array) $row['plugin_files'] as $file ) {
			$plugin[ $file ] = true;
		}
	}
	printf(
		"| %s | %d | %s | %d–%d | %d–%d | %s | %.1f |\n",
		$run,
		count( $rows ),
		implode( ',', array_unique( array_column( $rows, 'status' ) ) ),
		min( $queries ),
		max( $queries ),
		min( $files ),
		max( $files ),
		$plugin ? implode( ', ', array_keys( $plugin ) ) : '—',
		$median( array_column( $rows, 'php_ms' ) )
	);
}
