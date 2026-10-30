<?php
declare(strict_types=1);

namespace Varizet\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Varizet\WooCommerce\Connect\Pkce;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Phone;
use Varizet\WooCommerce\Support\Redactor;

final class SupportTest extends TestCase {

	/**
	 * @dataProvider phones
	 */
	public function test_phone_normalisation( ?string $raw, ?string $expected ): void {
		self::assertSame( $expected, Phone::normalize_mobile( $raw ) );
	}

	public static function phones(): array {
		return array(
			'canonical'      => array( '09121234567', '09121234567' ),
			'persian digits' => array( '۰۹۱۲۱۲۳۴۵۶۷', '09121234567' ),
			'arabic digits'  => array( '٠٩١٢١٢٣٤٥٦٧', '09121234567' ),
			'+98 spaced'     => array( '+98 912 123 4567', '09121234567' ),
			'0098'           => array( '00989121234567', '09121234567' ),
			'98 prefix'      => array( '989121234567', '09121234567' ),
			'10 digits'      => array( '9121234567', '09121234567' ),
			'dashes'         => array( '0912-123-4567', '09121234567' ),
			'landline'       => array( '02188776655', null ),
			'short'          => array( '0912123', null ),
			'empty'          => array( '', null ),
			'null'           => array( null, null ),
		);
	}

	public function test_redactor_masks_secrets_and_cards(): void {
		$text = 'key vz_live_51HcAbCdEfGh3f9a and vz_test_abcdefgh1234 secret vz_dev_xyz vzd_abc '
			. 'Bearer abc.def card 6104337890124521 grouped 6104-3378-9012-4521 iban IR120570028780010123456701 '
			. 'phone 09121234567 pay https://pay.varizet.ir/p/kJ8s2QaX7mZr4TnB sig v1=5257a869e7aabbccddeeff';
		$out  = Redactor::text( $text );
		self::assertStringNotContainsString( '51HcAbCdEfGh', $out );
		self::assertStringContainsString( 'vz_live_••••3f9a', $out );
		self::assertStringContainsString( 'vz_test_••••1234', $out );
		self::assertStringContainsString( 'vz_dev_••••', $out );
		self::assertStringContainsString( 'vzd_••••', $out );
		self::assertStringContainsString( 'Bearer [redacted]', $out );
		self::assertStringNotContainsString( '6104337890124521', $out );
		self::assertStringNotContainsString( '6104-3378-9012', $out );
		self::assertStringContainsString( '****4521', $out );
		self::assertStringNotContainsString( '0570028780010123', $out );
		self::assertStringContainsString( '0912•••4567', $out );
		self::assertStringContainsString( '/p/kJ8s…', $out );
		self::assertStringContainsString( 'v1=[redacted]', $out );
	}

	public function test_redactor_context_keys(): void {
		$ctx = Redactor::context(
			array(
				'api_key'        => 'vz_live_secretsecret1234',
				'webhook_secret' => 'whsec_1',
				'code'           => 'VZ-8K2Q',
				'nested'         => array(
					'Authorization' => 'Bearer x',
					'note'          => 'card 6104337890124521',
				),
				'n'              => 5,
				'empty_secret'   => '',
			)
		);
		self::assertSame( '[redacted]', $ctx['api_key'] );
		self::assertSame( '[redacted]', $ctx['webhook_secret'] );
		self::assertSame( 'VZ-8K2Q', $ctx['code'], 'order codes are not secrets' );
		self::assertSame( '[redacted]', $ctx['nested']['Authorization'] );
		self::assertSame( 'card ****4521', $ctx['nested']['note'] );
		self::assertSame( 5, $ctx['n'] );
		self::assertSame( '', $ctx['empty_secret'] );
	}

	public function test_format_helpers(): void {
		self::assertSame( '۸٬۵۰۰٬۴۳۰', Format::number( 8500430, true ) );
		self::assertSame( '8,500,430', Format::number( 8500430, false ) );
		self::assertSame( '09121234567', Format::to_latin_digits( '۰۹۱۲۱۲۳۴۵۶۷' ) );
		self::assertSame( '<span class="vz-amt" dir="ltr">8,500,<span class="vz-uniq">430</span></span>', Format::unique_amount_html( 8500430, 3, false ) );
		self::assertSame( '<span class="vz-amt" dir="ltr">8,50<span class="vz-uniq">0,430</span></span>', Format::unique_amount_html( 8500430, 4, false ) );
		self::assertSame( '<span class="vz-amt" dir="ltr">۸٬۵۰۰٬<span class="vz-uniq">۴۳۰</span></span>', Format::unique_amount_html( 8500430, 3, true ) );
		self::assertSame( '6104 3378 9012 4521', Format::pan( '6104337890124521' ) );
		self::assertSame( '…4521', Format::last4( '6104-3378-9012-4521' ) );
	}

	public function test_pkce_rfc7636_vector(): void {
		// RFC 7636 appendix B.
		self::assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
		$v = Pkce::verifier();
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43,128}$/', $v );
		self::assertNotSame( Pkce::state(), Pkce::state() );
	}
}
