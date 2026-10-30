<?php
declare(strict_types=1);

namespace Varizet\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Varizet\WooCommerce\Support\Amount;

final class AmountTest extends TestCase {

	/**
	 * @dataProvider conversions
	 */
	public function test_to_rial( $amount, string $currency, int $rial ): void {
		self::assertSame( $rial, Amount::to_rial( $amount, $currency ) );
	}

	public static function conversions(): array {
		return array(
			'IRR string'            => array( '8500000', 'IRR', 8500000 ),
			'IRR decimals string'   => array( '8500000.00', 'IRR', 8500000 ),
			'IRR fractional rounds' => array( '100.5', 'IRR', 101 ),
			'IRR rounds down'       => array( '100.49', 'IRR', 100 ),
			'IRT toman x10'         => array( '850000', 'IRT', 8500000 ),
			'IRT lowercase'         => array( '850000', 'irt', 8500000 ),
			'IRT with decimal'      => array( '850000.5', 'IRT', 8500005 ),
			'IRT float'             => array( 850000.0, 'IRT', 8500000 ),
			'IRT float noise'       => array( 0.1 + 0.2, 'IRT', 3 ),
			'IRT int'               => array( 1500, 'IRT', 15000 ),
			'IRHR thousand rial'    => array( '8500', 'IRHR', 8500000 ),
			'IRHT thousand toman'   => array( '850', 'IRHT', 8500000 ),
			'IRHT fraction'         => array( '850.25', 'IRHT', 8502500 ),
			'grouped string'        => array( '8,500,000', 'IRR', 8500000 ),
			'persian separator'     => array( '850٬000', 'IRT', 8500000 ),
			'zero'                  => array( '0', 'IRT', 0 ),
			'long fraction'         => array( '1.99999', 'IRR', 2 ),
			'fraction carry'        => array( '9.99996', 'IRT', 100 ),
			'no double rounding'    => array( '1000.49999', 'IRR', 1000 ),
			'half rial exactly'     => array( '1000.5', 'IRR', 1001 ),
			'tiny below half'       => array( '100.000049999', 'IRHT', 1000000 ),
			'tiny at half'          => array( '0.00005', 'IRHT', 1 ),
			'past kept digits'      => array( '1.4999999999999999', 'IRR', 1 ),
		);
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_invalid_amounts( $amount, string $currency ): void {
		$this->expectException( \InvalidArgumentException::class );
		Amount::to_rial( $amount, $currency );
	}

	public static function invalid(): array {
		return array(
			'negative'        => array( '-1', 'IRR' ),
			'negative float'  => array( -1.5, 'IRT' ),
			'garbage'         => array( '12abc', 'IRR' ),
			'empty'           => array( '', 'IRR' ),
			'exponent'        => array( '1e5', 'IRR' ),
			'usd'             => array( '10', 'USD' ),
			'overflow'        => array( '9999999999999999', 'IRHT' ),
			'nan'             => array( NAN, 'IRR' ),
		);
	}

	public function test_from_rial_round_trips(): void {
		self::assertSame( '850043', Amount::from_rial( 8500430, 'IRT' ) );
		self::assertSame( '850043.1', Amount::from_rial( 8500431, 'IRT' ) );
		self::assertSame( '8500430', Amount::from_rial( 8500430, 'IRR' ) );
		self::assertSame( '850.043', Amount::from_rial( 8500430, 'IRHT' ) );
		self::assertSame( '0.001', Amount::from_rial( 10, 'IRHT' ) );
		foreach ( array( 'IRR', 'IRT', 'IRHR', 'IRHT' ) as $c ) {
			foreach ( array( 10000, 8500430, 8499570, 123456789 ) as $r ) {
				self::assertSame( $r, Amount::to_rial( Amount::from_rial( $r, $c ), $c ), "{$c} {$r}" );
			}
		}
	}

	public function test_support_and_unique_digits(): void {
		self::assertTrue( Amount::is_supported( 'IRT' ) );
		self::assertFalse( Amount::is_supported( 'EUR' ) );
		self::assertSame( 3, Amount::unique_digits( 8500000, 8500430 ) );
		self::assertSame( 3, Amount::unique_digits( 8500000, 8499570 ) );
		self::assertSame( 2, Amount::unique_digits( 8500000, 8500090 ) );
		self::assertSame( 3, Amount::unique_digits( 8500000, 8500000 ) );
	}
}
