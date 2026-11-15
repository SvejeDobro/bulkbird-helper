<?php
/**
 * Primjenjuje namjerne kvarove iz tests/faults/faults.php jedan po jedan i očekuje da
 * jedinični testovi ili čuvari padnu. Nakon svakog kvara vraća izvornu datoteku.
 * Izlaz 0 samo ako je SVAKI kvar uhvaćen.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
chdir( $root );

$dirty = trim( (string) shell_exec( 'git status --porcelain -- bulkbird-helper.php uninstall.php includes' ) );
if ( '' !== $dirty ) {
	fwrite( STDERR, "Kod plugina ima necommitane izmjene; kvarovi se pokreću samo nad čistim kodom.\n" );
	exit( 2 );
}

$php    = PHP_BINARY;
$checks = array(
	'jedinični testovi' => escapeshellarg( $php ) . ' vendor/bin/phpunit --testsuite unit --bootstrap tests/unit/bootstrap.php',
	'čuvari'            => 'bin/check-guards.sh',
);

$baseline_ok = true;
foreach ( $checks as $name => $cmd ) {
	exec( $cmd . ' > /dev/null 2>&1', $o, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "Provjera '$name' pada i bez kvara; najprije to popravi.\n" );
		$baseline_ok = false;
	}
}
if ( ! $baseline_ok ) {
	exit( 2 );
}

$missed = 0;
foreach ( require $root . '/tests/faults/faults.php' as $fault ) {
	list( $id, $description, $file, $search, $replace ) = $fault;
	$original = (string) file_get_contents( $file );
	if ( 1 !== substr_count( $original, $search ) ) {
		printf( "%-7s GREŠKA  traženi tekst nije pronađen točno jednom u %s\n", $id, $file );
		++$missed;
		continue;
	}
	file_put_contents( $file, str_replace( $search, $replace, $original ) );
	$caught = array();
	try {
		foreach ( $checks as $name => $cmd ) {
			exec( $cmd . ' > /dev/null 2>&1', $o, $code );
			if ( 0 !== $code ) {
				$caught[] = $name;
			}
		}
	} finally {
		file_put_contents( $file, $original );
	}
	if ( $caught ) {
		printf( "%-7s uhvaćen (%s) — %s\n", $id, implode( ', ', $caught ), $description );
	} else {
		printf( "%-7s NIJE UHVAĆEN — %s\n", $id, $description );
		++$missed;
	}
}

$dirty = trim( (string) shell_exec( 'git status --porcelain -- bulkbird-helper.php uninstall.php includes' ) );
if ( '' !== $dirty ) {
	fwrite( STDERR, "Nakon kvarova kod nije vraćen u izvorno stanje!\n" );
	exit( 2 );
}
echo 0 === $missed ? "Svi namjerni kvarovi su uhvaćeni.\n" : "$missed kvar(ova) nije uhvaćeno.\n";
exit( 0 === $missed ? 0 : 1 );
