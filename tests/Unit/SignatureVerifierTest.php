<?php
declare(strict_types=1);

namespace Varizet\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Varizet\WooCommerce\Webhook\SignatureVerifier;

final class SignatureVerifierTest extends TestCase {

	private const SECRET = 'whsec_test_0001';
	private const BODY   = '{"id":"evt_01JBX5ABCDEF","type":"order.paid","created_at":"2026-10-28T10:34:12Z","livemode":true,"data":{"object":{"id":"ord_01JBX4Q8M6","status":"paid"}}}';

	public function test_shared_contracts_vector_verifies(): void {
		// Same HMAC scheme as the API's device channel and webhooks (packages/contracts/test).
		$v = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/signature.vector.json' ), true );
		$r = SignatureVerifier::verify( $v['header'], $v['body'], array( 'live' => $v['secret'] ), (int) $v['timestamp'] + 10 );
		self::assertTrue( $r['ok'] );
		self::assertSame( array( 'live' ), $r['matched'] );
		self::assertSame( $v['header'], SignatureVerifier::build_header( array( $v['secret'] ), (int) $v['timestamp'], $v['body'] ) );
	}

	public function test_valid_signature_within_tolerance(): void {
		$t = 1761647652;
		$h = SignatureVerifier::build_header( array( self::SECRET ), $t, self::BODY );
		foreach ( array( $t, $t + 300, $t - 300 ) as $now ) {
			$r = SignatureVerifier::verify( $h, self::BODY, array( 'live' => self::SECRET ), $now );
			self::assertTrue( $r['ok'], "now={$now}" );
		}
	}

	public function test_expired_timestamp_is_rejected_before_hmac(): void {
		$t = 1761647652;
		$h = SignatureVerifier::build_header( array( self::SECRET ), $t, self::BODY );
		$r = SignatureVerifier::verify( $h, self::BODY, array( 'live' => self::SECRET ), $t + 301 );
		self::assertFalse( $r['ok'] );
		self::assertSame( 'expired', $r['reason'] );
		self::assertSame( 301, $r['skew'] );
		self::assertSame( 'expired', SignatureVerifier::verify( $h, self::BODY, array( 'live' => self::SECRET ), $t - 301 )['reason'] );
	}

	public function test_tampered_body_or_wrong_secret_is_mismatch(): void {
		$t = 1761647652;
		$h = SignatureVerifier::build_header( array( self::SECRET ), $t, self::BODY );
		self::assertSame( 'mismatch', SignatureVerifier::verify( $h, self::BODY . ' ', array( 'live' => self::SECRET ), $t )['reason'] );
		self::assertSame( 'mismatch', SignatureVerifier::verify( $h, self::BODY, array( 'live' => 'other' ), $t )['reason'] );
		$zeros = 't=' . $t . ',v1=' . str_repeat( '0', 64 );
		self::assertSame( 'mismatch', SignatureVerifier::verify( $zeros, self::BODY, array( 'live' => self::SECRET ), $t )['reason'] );
	}

	public function test_rotation_header_with_two_signatures_and_mode_detection(): void {
		$t = 1761647652;
		$h = SignatureVerifier::build_header( array( 'new-secret', 'old-secret' ), $t, self::BODY );
		$r = SignatureVerifier::verify( $h, self::BODY, array( 'live' => 'old-secret', 'test' => 'test-secret' ), $t );
		self::assertTrue( $r['ok'] );
		self::assertSame( array( 'live' ), $r['matched'] );
		$r = SignatureVerifier::verify( SignatureVerifier::build_header( array( 'test-secret' ), $t, self::BODY ), self::BODY, array( 'live' => 'old-secret', 'test' => 'test-secret' ), $t );
		self::assertSame( array( 'test' ), $r['matched'] );
	}

	/**
	 * @dataProvider malformed_headers
	 */
	public function test_malformed_headers( ?string $header ): void {
		$r = SignatureVerifier::verify( $header, self::BODY, array( 'live' => self::SECRET ), 1761647652 );
		self::assertFalse( $r['ok'] );
		self::assertSame( 'malformed', $r['reason'] );
	}

	public static function malformed_headers(): array {
		$sig = str_repeat( 'a', 64 );
		return array(
			'missing'        => array( null ),
			'empty'          => array( '' ),
			'no t'           => array( 'v1=' . $sig ),
			'no v1'          => array( 't=1761647652' ),
			'uppercase hex'  => array( 't=1761647652,v1=' . strtoupper( $sig ) ),
			'short hex'      => array( 't=1761647652,v1=abc' ),
			'non numeric t'  => array( 't=abc,v1=' . $sig ),
			'two t'          => array( 't=1,t=2,v1=' . $sig ),
			'part without ='  => array( 't=1761647652,junk,v1=' . $sig ),
			'too long'       => array( 't=1761647652,v1=' . $sig . str_repeat( ',x=1', 300 ) ),
		);
	}

	public function test_unknown_schemes_are_ignored(): void {
		$t = 1761647652;
		$h = 'v0=deadbeef,' . SignatureVerifier::build_header( array( self::SECRET ), $t, self::BODY ) . ',v2=whatever';
		self::assertTrue( SignatureVerifier::verify( $h, self::BODY, array( 'live' => self::SECRET ), $t )['ok'] );
	}

	public function test_empty_secrets_never_verify(): void {
		$t = 1761647652;
		$h = SignatureVerifier::build_header( array( '' ), $t, self::BODY );
		self::assertFalse( SignatureVerifier::verify( $h, self::BODY, array( 'live' => '' ), $t )['ok'] );
		self::assertFalse( SignatureVerifier::verify( $h, self::BODY, array(), $t )['ok'] );
	}
}
