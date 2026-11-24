<?php

namespace Tests\Unit;

use App\Support\VocusAddressParser;
use App\Support\VocusAddressStandard;
use PHPUnit\Framework\TestCase;

/**
 * Pure logic (no framework): VW-02 parser regression cases and the Vocus
 * address standard lookups (VW-07).
 */
class VocusAddressParserTest extends TestCase
{
    public function test_hunter_st_is_not_corrupted(): void
    {
        $p = VocusAddressParser::parse('Hunter St');

        $this->assertSame('HUNTER', $p['Main.StreetName']);
        $this->assertSame('ST', $p['Main.StreetType']);
        $this->assertArrayNotHasKey('Main.State', $p);
    }

    public function test_state_inside_words_is_never_removed(): void
    {
        // "NT" inside Hunter, "SA" inside Sandy, "VIC" inside Victoria, "WA" inside Wantirna.
        $p = VocusAddressParser::parse('12 Hunter St, Newcastle NT 0800');
        $this->assertSame('HUNTER', $p['Main.StreetName']);
        $this->assertSame('NT', $p['Main.State']);
        $this->assertSame('0800', $p['Main.PostCode']);
        $this->assertSame('NEWCASTLE', $p['Main.Suburb']);

        $p = VocusAddressParser::parse('5 Sandy Rd, Casuarina SA 5000');
        $this->assertSame('SANDY', $p['Main.StreetName']);
        $this->assertSame('SA', $p['Main.State']);

        $p = VocusAddressParser::parse('1 Victoria Ave, Wantirna VIC 3152');
        $this->assertSame('VICTORIA', $p['Main.StreetName']);
        $this->assertSame('AV', $p['Main.StreetType'], 'AVE is normalised to the standard AV');
        $this->assertSame('WANTIRNA', $p['Main.Suburb']);
    }

    public function test_sandy_bay_rd(): void
    {
        $p = VocusAddressParser::parse('Sandy Bay Rd');

        $this->assertSame('SANDY BAY', $p['Main.StreetName']);
        $this->assertSame('RD', $p['Main.StreetType']);
    }

    public function test_victoria_ave(): void
    {
        $p = VocusAddressParser::parse('Victoria Ave');

        $this->assertSame('VICTORIA', $p['Main.StreetName']);
        $this->assertSame('AV', $p['Main.StreetType']);
    }

    public function test_address_without_commas(): void
    {
        $p = VocusAddressParser::parse('12 Main St Hobart TAS 7000');

        $this->assertSame(12, $p['Main.Street1stNumber']);
        $this->assertSame('MAIN', $p['Main.StreetName']);
        $this->assertSame('ST', $p['Main.StreetType']);
        $this->assertSame('HOBART', $p['Main.Suburb']);
        $this->assertSame('TAS', $p['Main.State']);
        $this->assertSame('7000', $p['Main.PostCode']);
    }

    public function test_production_report_free_text(): void
    {
        $p = VocusAddressParser::parse('4 cummins dr, summertown sa 5141');

        $this->assertSame([
            'Main.PostCode' => '5141',
            'Main.State' => 'SA',
            'Main.Street1stNumber' => 4,
            'Main.StreetType' => 'DR',
            'Main.StreetName' => 'CUMMINS',
            'Main.Suburb' => 'SUMMERTOWN',
        ], $p);
    }

    public function test_production_report_manual_form(): void
    {
        $p = VocusAddressParser::fromStructured([
            'street_number' => '4', 'street_name' => 'Cummins', 'street_type' => 'DR',
            'suburb' => 'Summertown', 'state' => 'SA', 'postcode' => '5141',
        ]);

        $this->assertSame([
            'Main.Street1stNumber' => 4,
            'Main.StreetName' => 'CUMMINS',
            'Main.StreetType' => 'DR',
            'Main.Suburb' => 'SUMMERTOWN',
            'Main.State' => 'SA',
            'Main.PostCode' => '5141',
        ], $p);
    }

    public function test_unit_prefixes(): void
    {
        $p = VocusAddressParser::parse('2/99 Example St, Upper Melbourne VIC 4009');
        $this->assertSame(2, $p['Main.Unit1stNumber']);
        $this->assertSame(99, $p['Main.Street1stNumber']);
        $this->assertSame('UPPER MELBOURNE', $p['Main.Suburb']);

        $p = VocusAddressParser::parse('Unit 5, 12A Smith Street, Newtown NSW 2042');
        $this->assertSame('U', $p['Main.AddressType']);
        $this->assertSame(5, $p['Main.Unit1stNumber']);
        $this->assertSame(12, $p['Main.Street1stNumber']);
        $this->assertSame('A', $p['Main.Street1stNumberSuffix']);
        $this->assertSame('ST', $p['Main.StreetType'], 'Street is normalised to ST');

        $p = VocusAddressParser::parse('Shop 4/100 George St Sydney NSW 2000');
        $this->assertSame('SP', $p['Main.AddressType']);
        $this->assertSame(4, $p['Main.Unit1stNumber']);

        $p = VocusAddressParser::parse('2A/12B Main St, Hobart TAS 7000');
        $this->assertSame('A', $p['Main.Unit1stSuffix']);
        $this->assertSame('B', $p['Main.Street1stNumberSuffix']);
    }

    public function test_level_prefix(): void
    {
        $p = VocusAddressParser::parse('Level 10, 452 Flinders Street, Melbourne');

        $this->assertSame('L', $p['Main.FloorType']);
        $this->assertSame(10, $p['Main.FloorNumber']);
        $this->assertSame(452, $p['Main.Street1stNumber']);
        $this->assertSame('FLINDERS', $p['Main.StreetName']);
        $this->assertSame('ST', $p['Main.StreetType']);
        $this->assertSame('MELBOURNE', $p['Main.Suburb']);
    }

    public function test_lengths_follow_the_xsd(): void
    {
        $p = VocusAddressParser::parse('1 ' . str_repeat('A', 40) . ' St, ' . str_repeat('B', 60));

        $this->assertSame(25, strlen($p['Main.StreetName']));
        $this->assertSame(40, strlen($p['Main.Suburb']));
    }

    public function test_standard_normalisation(): void
    {
        $this->assertSame('AV', VocusAddressStandard::streetType('AVE'));
        $this->assertSame('AV', VocusAddressStandard::streetType('Avenue'));
        $this->assertSame('CR', VocusAddressStandard::streetType('CRES'));
        $this->assertSame('BVD', VocusAddressStandard::streetType('BLVD'));
        $this->assertSame('WY', VocusAddressStandard::streetType('WAY'));
        $this->assertSame('DR', VocusAddressStandard::streetType('drive'));
        $this->assertSame('L', VocusAddressStandard::streetType('LANE'));
        $this->assertNull(VocusAddressStandard::streetType('LN'), 'LN is not a street type in the standard');
        $this->assertSame('U', VocusAddressStandard::unitType('UNT'));
        $this->assertSame('L', VocusAddressStandard::levelType('LVL'));
        $this->assertSame('AAT', VocusAddressStandard::state('aat'));
        $this->assertNull(VocusAddressStandard::state('XX'));
        $this->assertContains('ACT', VocusAddressStandard::stateCodes());
    }
}
