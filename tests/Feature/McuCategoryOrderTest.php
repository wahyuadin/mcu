<?php

namespace Tests\Feature;

use App\Models\McuCategory;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McuCategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reorder_all_categories_including_inactive_without_changing_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = McuCategory::factory()->create(['sort_order' => 0]);
        $second = McuCategory::factory()->create(['sort_order' => 1, 'is_active' => false]);
        $schema = [['id' => $first->id, 'name' => $first->name, 'order' => 0, 'items' => []]];
        $package = Paket::factory()->master()->create(['dynamic_schema' => $schema]);
        $url = route('mcu-category-order.show');
        $response = $this->getJson($url)->assertOk()->assertJsonPath('items.0.id', $first->id)->assertJsonPath('items.1.is_active', false);

        $this->putJson(route('mcu-category-order.update'), ['version' => $response->json('version'), 'items' => [$second->id, $first->id]])
            ->assertOk()->assertJsonPath('message', 'Urutan kategori berhasil disimpan. Paket dan hasil pemeriksaan lama tidak berubah.');

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame($schema, $package->fresh()->dynamic_schema);
        $this->getJson($url)->assertOk()->assertJsonPath('items.0.id', $second->id);
    }

    public function test_invalid_orders_are_rejected_without_partial_writes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $categories = McuCategory::factory()->count(2)->create(['sort_order' => 8]);
        $version = $this->getJson(route('mcu-category-order.show'))->json('version');
        $url = route('mcu-category-order.update');

        $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'items']);
        $this->putJson($url, ['version' => $version, 'items' => [$categories[0]->id]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($url, ['version' => $version, 'items' => [$categories[0]->id, $categories[0]->id]])->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->putJson($url, ['version' => $version, 'items' => [$categories[0]->id, fake()->uuid()]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($url, ['version' => $version, 'items' => ['first' => $categories[0]->id, 'second' => $categories[1]->id]])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame([8, 8], McuCategory::pluck('sort_order')->all());
    }

    public function test_stale_versions_cannot_overwrite_added_or_reordered_categories(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = McuCategory::factory()->create(['sort_order' => 0]);
        $url = route('mcu-category-order.show');
        $version = $this->getJson($url)->json('version');
        $second = McuCategory::factory()->create(['sort_order' => 1]);

        $this->putJson($url, ['version' => $version, 'items' => [$first->id]])->assertUnprocessable()->assertJsonValidationErrors('version');
        $version = $this->getJson($url)->json('version');
        $this->putJson($url, ['version' => $version, 'items' => [$second->id, $first->id]])->assertOk();
        $this->putJson($url, ['version' => $version, 'items' => [$first->id, $second->id]])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->assertSame(0, $second->fresh()->sort_order);
    }

    public function test_only_admin_can_load_or_save_category_order(): void
    {
        $url = route('mcu-category-order.show');
        $this->get($url)->assertRedirect(route('login'));
        $this->put($url, [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, [])->assertForbidden();
    }

    public function test_order_endpoint_returns_empty_after_bulk_deletion_and_never_restores_deleted_categories(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create(['name' => 'Kategori dihapus']);
        $url = route('mcu-category-order.show');
        $before = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'items');

        $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$category->id]])->assertRedirect(route('mcu-category.index'));

        $after = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(0, 'items');
        $this->assertNotSame($before->json('version'), $after->json('version'));
        $this->get(route('mcu-category.create'))->assertOk()->assertSee('Atur urutan kategori');
        $this->putJson(route('mcu-category-order.update'), ['version' => $before->json('version'), 'items' => [$category->id]])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseMissing('mcu_categories', ['id' => $category->id]);
    }

    public function test_category_forms_use_cards_and_preserve_order_when_saving_other_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create(['sort_order' => 7]);
        $this->get(route('mcu-category.index'))->assertOk()->assertSee('data-order-category="all"', false)->assertSee('Atur urutan kategori');
        $this->get(route('mcu-category.edit', $category))->assertOk()->assertSee('data-item-order-modal', false)->assertDontSee('name="sort_order"', false);
        $this->get(route('mcu-category.create'))->assertOk()->assertDontSee('name="sort_order"', false);

        $this->put(route('mcu-category.update', $category), ['name' => 'Kategori diubah', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post(route('mcu-category.store'), ['name' => 'Kategori baru', 'is_active' => 1])->assertSessionHasNoErrors();

        $this->assertSame(7, $category->fresh()->sort_order);
        $this->assertDatabaseHas('mcu_categories', ['name' => 'Kategori baru', 'sort_order' => 8]);
    }
}
