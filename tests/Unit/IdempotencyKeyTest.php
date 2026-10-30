<?php
declare(strict_types=1);

namespace Varizet\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Varizet\WooCommerce\Support\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase {

	public function test_key_per_attempt(): void {
		self::assertSame( 'wc-123-1-ab12cd34', IdempotencyKey::for_attempt( 123, 1, 'ab12cd34' ) );
		self::assertSame( 'wc-123-2-ab12cd34', IdempotencyKey::for_attempt( 123, 2, 'ab12cd34' ) );
		self::assertNotSame( IdempotencyKey::for_attempt( 123, 1, 'site-a' ), IdempotencyKey::for_attempt( 123, 2, 'site-a' ) );
	}

	public function test_site_suffix_is_sanitised_and_optional(): void {
		self::assertSame( 'wc-5-1-abcdef12', IdempotencyKey::for_attempt( 5, 1, 'AB-CD_EF12XYZ' ) );
		self::assertSame( 'wc-5-1', IdempotencyKey::for_attempt( 5, 1, '' ) );
	}

	public function test_invalid_attempts_throw(): void {
		$this->expectException( \InvalidArgumentException::class );
		IdempotencyKey::for_attempt( 5, 0, 'x' );
	}

	public function test_merchant_order_id(): void {
		self::assertSame( '12345', IdempotencyKey::merchant_order_id( '', '12345', 1 ) );
		self::assertSame( 'KN-12345-2', IdempotencyKey::merchant_order_id( 'KN-', '12345', 2 ) );
		self::assertSame( 'wc-3', IdempotencyKey::merchant_order_id( '', '', 3 ) );
		self::assertSame( 'INV12', IdempotencyKey::merchant_order_id( 'INV ', '12', 1 ), 'spaces removed' );
		$long = IdempotencyKey::merchant_order_id( str_repeat( 'P', 80 ), '99', 12 );
		self::assertSame( 64, strlen( $long ) );
		self::assertStringEndsWith( '-12', $long );
	}

	public function test_site_fingerprint_depends_on_install_and_host(): void {
		$a = IdempotencyKey::site_fingerprint( 'install-1', 'https://shop.example/' );
		self::assertSame( 8, strlen( $a ) );
		self::assertSame( $a, IdempotencyKey::site_fingerprint( 'install-1', 'http://shop.example' ) );
		self::assertNotSame( $a, IdempotencyKey::site_fingerprint( 'install-1', 'https://staging.shop.example' ) );
		self::assertNotSame( $a, IdempotencyKey::site_fingerprint( 'install-2', 'https://shop.example' ) );
	}
}
