<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McuMasterActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_display_edit_and_delete_buttons_with_accessible_icons(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create();
        $package = Paket::factory()->master()->create();

        $this->get(route('paket.index'))->assertOk()->assertSee(route('paket.edit', $package))->assertSee(route('paket.destroy', $package))->assertSee('aria-label="Hapus '.$package->nama_paket.'"', false)->assertSee('aria-hidden="true"', false);
        $this->get(route('mcu-category.index'))->assertOk()->assertSee(route('mcu-category.destroy', $item->category))->assertSee('Hapus');
        $this->get(route('mcu-item.index'))->assertOk()->assertSee(route('mcu-item.destroy', $item))->assertSee('Ubah')->assertSee('Hapus');
    }

    public function test_search_filters_categories_and_keeps_query_in_pagination(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        McuCategory::factory()->count(31)->create(['name' => 'Laboratorium khusus']);
        McuCategory::factory()->create(['name' => 'Radiologi']);

        $this->get(route('mcu-category.index', ['q' => '  Laboratorium  ']))->assertOk()
            ->assertSee('Laboratorium khusus')->assertDontSee('Radiologi')
            ->assertViewHas('search', 'Laboratorium')->assertViewHas('categories', fn ($categories): bool => $categories->total() === 31 && str_contains($categories->nextPageUrl(), 'q='));
        $this->get(route('mcu-category.index', ['q' => 'Tidak ditemukan']))->assertOk()->assertSee('Tidak ada kategori yang cocok');
        $this->get(route('mcu-category.index'))->assertOk()->assertViewHas('categories', fn ($categories): bool => $categories->total() === 32);
        $this->get(route('mcu-category.index', ['q' => ['invalid']]))->assertSessionHasErrors('q');
    }

    public function test_item_search_combines_category_filter_and_keeps_filters_in_pagination(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        McuItem::factory()->count(31)->create(['mcu_category_id' => $category->id, 'label' => 'Hemoglobin khusus']);
        McuItem::factory()->create(['mcu_category_id' => $category->id, 'label' => 'Leukosit']);
        McuItem::factory()->create(['label' => 'Hemoglobin kategori lain']);

        $this->get(route('mcu-item.index', ['q' => '  Hemoglobin  ', 'category' => $category->id]))->assertOk()
            ->assertSee('Hemoglobin khusus')->assertDontSee('Leukosit')->assertDontSee('Hemoglobin kategori lain')
            ->assertViewHas('search', 'Hemoglobin')
            ->assertViewHas('items', fn ($items): bool => $items->total() === 31 && str_contains($items->nextPageUrl(), 'q=') && str_contains($items->nextPageUrl(), 'category='.$category->id));
        $this->get(route('mcu-item.index', ['q' => 'Tidak ditemukan']))->assertOk()->assertSee('Tidak ada item yang cocok');
        $this->get(route('mcu-item.index'))->assertOk()->assertViewHas('items', fn ($items): bool => $items->total() === 33);
        $this->get(route('mcu-item.index', ['q' => ['invalid']]))->assertSessionHasErrors('q');
    }

    public function test_deleting_master_item_then_empty_category_preserves_saved_mapping(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create();
        $category = $item->category;
        $schema = [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => [$item->definition()]]];
        $package = Paket::factory()->master()->create(['dynamic_schema' => $schema]);

        $this->delete(route('mcu-item.destroy', $item))->assertRedirect(route('mcu-item.index', ['category' => $category->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('mcu_items', ['id' => $item->id]);
        $this->delete(route('mcu-category.destroy', $category))->assertRedirect(route('mcu-category.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('mcu_categories', ['id' => $category->id]);
        $this->assertSame($schema, $package->fresh()->dynamic_schema);
        $this->get(route('paket.show', $package))->assertOk()->assertSee($item->label);
    }

    public function test_category_with_items_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create();

        $this->from(route('mcu-category.index'))->delete(route('mcu-category.destroy', $item->category))->assertRedirect(route('mcu-category.index'))->assertSessionHasErrors('category');

        $this->assertModelExists($item);
        $this->assertModelExists($item->category);
    }

    public function test_package_without_participants_can_be_deleted_but_used_package_is_protected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $unused = Paket::factory()->master()->create();
        $participant = Karyawan::factory()->create();
        $used = $participant->paket;

        $this->delete(route('paket.destroy', $unused))->assertRedirect(route('paket.index'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($unused);
        $this->from(route('paket.index'))->delete(route('paket.destroy', $used))->assertRedirect(route('paket.index'))->assertSessionHasErrors('paket');
        $this->assertNotSoftDeleted($used);
        $this->assertSame($used->id, $participant->fresh()->paket_id);
    }

    public function test_guests_and_operators_cannot_delete_masters(): void
    {
        $item = McuItem::factory()->create();
        $package = Paket::factory()->master()->create();
        $urls = [route('mcu-item.destroy', $item), route('mcu-category.destroy', $item->category), route('paket.destroy', $package)];
        foreach ($urls as $url) {
            $this->delete($url)->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        foreach ($urls as $url) {
            $this->delete($url)->assertForbidden();
        }
        $this->assertModelExists($item);
        $this->assertModelExists($item->category);
        $this->assertNotSoftDeleted($package);
    }
}
