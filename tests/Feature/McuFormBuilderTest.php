<?php

namespace Tests\Feature;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McuFormBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_displays_question_cards_and_only_supported_input_types(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('mcu-item.create'))->assertOk()->assertSee('data-form-builder', false)
            ->assertSee('Tambah pertanyaan')->assertSee('Duplikasi')->assertSee('Pratinjau form')
            ->assertDontSee('<option value="label"', false)->assertDontSee('<option value="existing"', false);
        $item = McuItem::factory()->create(['input_type' => 'existing']);
        $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('Item lama disesuaikan menjadi isian teks')
            ->assertDontSee('<option value="existing"', false)->assertDontSee('name="sort_order"', false);
        $this->assertSame('existing', $item->fresh()->input_type);
    }

    public function test_multiple_questions_can_be_saved_in_one_category_without_untrusted_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $this->post(route('mcu-item.store'), ['mcu_category_id' => $category->id, 'questions' => [
            $this->question('Hepatitis', 'radio') + ['yes_report' => 'Ada riwayat', 'no_report' => 'Normal', 'legacy_key' => 'bmi'],
            $this->question('Keterangan', 'text'), $this->question('Jumlah', 'number'),
        ]])->assertSessionHasNoErrors()->assertRedirect(route('mcu-item.index', ['category' => $category->id]));
        $this->assertSame(['Hepatitis', 'Keterangan', 'Jumlah'], $category->items()->pluck('label')->all());
        $this->assertDatabaseCount('mcu_items', 3);
        $this->assertNull(McuItem::where('label', 'Hepatitis')->firstOrFail()->legacy_key);
        $this->assertNull(McuItem::where('label', 'Keterangan')->firstOrFail()->no_report);
    }

    public function test_invalid_question_prevents_partial_creation_and_retains_draft(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $payload = ['mcu_category_id' => $category->id, 'questions' => [$this->question('Valid', 'text'), $this->question('Perlu mapping', 'radio')]];
        $this->from(route('mcu-item.create'))->post(route('mcu-item.store'), $payload)->assertSessionHasErrors('questions.1.no_report')->assertSessionHasInput('questions.0.label', 'Valid');
        $this->assertDatabaseCount('mcu_items', 0);
        $this->get(route('mcu-item.create'))->assertOk()->assertSee('value="Valid"', false)->assertSee('value="Perlu mapping"', false);
    }

    public function test_edit_updates_primary_and_creates_duplicate_without_changing_saved_package(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create(['input_type' => 'existing', 'sort_order' => 7]);
        $old = Paket::factory()->master()->create(['active_items' => ['kulit' => true]]);
        $this->put(route('mcu-item.update', $item), ['mcu_category_id' => $item->mcu_category_id, 'questions' => [
            $this->question('Isian disesuaikan', 'text'), $this->question('Salinan', 'number'),
        ]])->assertSessionHasNoErrors();
        $this->assertSame('text', $item->fresh()->input_type);
        $this->assertSame(7, $item->fresh()->sort_order);
        $this->assertDatabaseHas('mcu_items', ['label' => 'Salinan', 'sort_order' => 8]);
        $this->assertSame(['kulit' => true], $old->fresh()->active_items);
    }

    public function test_builder_rejects_removed_types_and_does_not_allow_operator_access(): void
    {
        $category = McuCategory::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->post(route('mcu-item.store'), ['mcu_category_id' => $category->id, 'questions' => [$this->question('Ditolak', 'text')]])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['label', 'existing'] as $type) {
            $this->post(route('mcu-item.store'), ['mcu_category_id' => $category->id, 'questions' => [$this->question('Ditolak', $type)]])->assertSessionHasErrors('questions.0.input_type');
        }
        $this->assertDatabaseCount('mcu_items', 0);
    }

    /** @return array{label: string, input_type: string, is_required: int, is_active: int} */
    private function question(string $label, string $type): array
    {
        return ['label' => $label, 'input_type' => $type, 'is_required' => 1, 'is_active' => 1];
    }
}
