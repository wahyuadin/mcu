<?php

namespace Tests\Feature;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McuItemOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reorder_all_items_including_inactive_items_without_changing_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $first = McuItem::factory()->create(['mcu_category_id' => $category->id, 'label' => 'Pertama', 'sort_order' => 0]);
        $second = McuItem::factory()->create(['mcu_category_id' => $category->id, 'label' => 'Kedua', 'sort_order' => 1]);
        $inactive = McuItem::factory()->create(['mcu_category_id' => $category->id, 'label' => 'Nonaktif', 'sort_order' => 2, 'is_active' => false]);
        $schema = [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => [$first->definition(), $second->definition()]]];
        $package = Paket::factory()->master()->create(['dynamic_schema' => $schema]);
        $url = route('mcu-item-order.show', $category);
        $response = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('items.0.id', $first->id)->assertJsonPath('items.2.is_active', false);
        $this->putJson($url, ['version' => $response->json('version'), 'items' => [$second->id, $inactive->id, $first->id]])->assertOk()->assertJsonPath('message', 'Urutan item berhasil disimpan. Paket dan hasil pemeriksaan lama tidak berubah.');
        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $inactive->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame($schema, $package->fresh()->dynamic_schema);
        $this->getJson($url)->assertOk()->assertJsonPath('items.0.id', $second->id)->assertJsonPath('items.2.id', $first->id);
        $this->post(route('paket.store'), ['nama_paket' => 'Urutan terbaru', 'master_items' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertSame($second->id, Paket::where('nama_paket', 'Urutan terbaru')->firstOrFail()->dynamic_schema[0]['items'][0]['id']);
    }

    public function test_reorder_rejects_missing_duplicate_and_cross_category_ids_without_partial_writes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $items = McuItem::factory()->count(2)->create(['mcu_category_id' => $category->id, 'sort_order' => 8]);
        $other = McuItem::factory()->create();
        $url = route('mcu-item-order.show', $category);
        $version = $this->getJson($url)->json('version');
        $this->putJson($url, ['version' => $version, 'items' => [$items[0]->id]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($url, ['version' => $version, 'items' => [$items[0]->id, $items[0]->id]])->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->putJson($url, ['version' => $version, 'items' => [$items[0]->id, $other->id]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($url, ['version' => $version, 'items' => ['a' => $items[0]->id, 'b' => $items[1]->id]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(8, $items[0]->fresh()->sort_order);
        $this->assertSame(8, $items[1]->fresh()->sort_order);
    }

    public function test_stale_order_is_rejected_when_an_item_is_added_or_reordered(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $first = McuItem::factory()->create(['mcu_category_id' => $category->id]);
        $url = route('mcu-item-order.show', $category);
        $version = $this->getJson($url)->json('version');
        $second = McuItem::factory()->create(['mcu_category_id' => $category->id]);
        $this->putJson($url, ['version' => $version, 'items' => [$first->id]])->assertUnprocessable()->assertJsonValidationErrors('version');
        $version = $this->getJson($url)->json('version');
        $this->putJson($url, ['version' => $version, 'items' => [$second->id, $first->id]])->assertOk();
        $this->putJson($url, ['version' => $version, 'items' => [$first->id, $second->id]])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame(0, $second->fresh()->sort_order);
    }

    public function test_only_admin_can_load_and_save_order(): void
    {
        $category = McuCategory::factory()->create();
        $url = route('mcu-item-order.show', $category);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, [])->assertForbidden();
    }

    public function test_edit_modal_does_not_submit_stale_sort_order_with_other_item_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create(['sort_order' => 7]);
        $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('data-item-order-modal', false)->assertSee('Atur urutan')->assertDontSee('name="sort_order"', false);
        $this->get(route('mcu-item.index'))->assertOk()->assertSee('data-item-order-open', false);
        $this->put(route('mcu-item.update', $item), ['mcu_category_id' => $item->mcu_category_id, 'label' => 'Diubah', 'input_type' => 'radio', 'yes_report' => 'Ya', 'no_report' => 'Tidak', 'is_required' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame(7, $item->fresh()->sort_order);
    }
}
