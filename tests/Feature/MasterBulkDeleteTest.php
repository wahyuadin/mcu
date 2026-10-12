<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class MasterBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_subcategory_bulk_deletion_skips_used_and_missing_rows_and_preserves_filters_and_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $empty = McuSubcategory::factory()->create(['name' => 'Kosong']);
        $used = McuSubcategory::factory()->create(['name' => 'Hematologi']);
        $unselected = McuSubcategory::factory()->create();
        $item = McuItem::factory()->create(['mcu_category_id' => $used->mcu_category_id, 'mcu_subcategory_id' => $used->id]);
        $schema = [['id' => $used->mcu_category_id, 'name' => $used->category->name, 'order' => 0, 'items' => [$item->definition()]]];
        $package = Paket::factory()->master()->create(['dynamic_schema' => $schema]);
        $filters = ['q' => 'Hema', 'category' => $used->mcu_category_id, 'page' => 2];

        $this->post(route('mcu-subcategory.bulk-destroy'), ['ids' => [$empty->id, $used->id, (string) Str::uuid()]] + $filters)
            ->assertRedirect(route('mcu-subcategory.index', $filters))
            ->assertSessionHas('bulkDeleteResults', fn (array $results): bool => count($results) === 3 && $results[0]['name'] === 'Kosong' && $results[0]['deleted'] && $results[1]['name'] === 'Hematologi' && ! $results[1]['deleted'] && ! $results[2]['deleted']);

        $this->assertDatabaseMissing('mcu_subcategories', ['id' => $empty->id]);
        $this->assertModelExists($used);
        $this->assertModelExists($unselected);
        $this->assertModelExists($item);
        $this->assertSame($schema, $package->fresh()->dynamic_schema);
        $this->get(route('mcu-subcategory.index'))->assertOk()->assertSee('Rincian penghapusan')->assertSee('Subkategori masih memiliki item');
        $this->post(route('mcu-subcategory.bulk-destroy'), ['ids' => [$empty->id]])
            ->assertSessionHas('bulkDeleteResults', fn (array $results): bool => ! $results[0]['deleted']);
    }

    public function test_package_bulk_deletion_only_removes_selected_unused_packages_and_reports_protected_rows(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $unused = Paket::factory()->master()->create();
        $unselected = Paket::factory()->master()->create();
        $participant = Karyawan::factory()->create();
        $missing = (string) Str::uuid();

        $response = $this->post(route('paket.bulk-destroy'), ['ids' => [$unused->id, $participant->paket_id, $missing], 'q' => 'MCU', 'page' => 2]);

        $response->assertRedirect(route('paket.index', ['q' => 'MCU', 'page' => 2]))->assertSessionHas('bulkDeleteResults', fn (array $results): bool => count($results) === 3 && $results[0]['deleted'] && ! $results[1]['deleted'] && ! $results[2]['deleted']);
        $this->assertSoftDeleted($unused);
        $this->assertNotSoftDeleted($unselected);
        $this->assertNotSoftDeleted($participant->paket);
        $this->assertSame($participant->paket_id, $participant->fresh()->paket_id);
        $this->get(route('paket.index'))->assertOk()->assertSee('Rincian penghapusan')->assertSee('Paket dengan peserta tidak dapat dihapus')->assertSee('Data sudah dihapus atau tidak ditemukan');
    }

    public function test_category_bulk_deletion_skips_categories_with_items(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $empty = McuCategory::factory()->create();
        $unselected = McuCategory::factory()->create();
        $item = McuItem::factory()->create();

        $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$empty->id, $item->mcu_category_id], 'q' => 'Laboratorium'])->assertRedirect(route('mcu-category.index', ['q' => 'Laboratorium']))->assertSessionHas('bulkDeleteResults', fn (array $results): bool => $results[0]['deleted'] && ! $results[1]['deleted']);

        $this->assertDatabaseMissing('mcu_categories', ['id' => $empty->id]);
        $this->assertModelExists($item->category);
        $this->assertModelExists($item);
        $this->assertModelExists($unselected);
    }

    public function test_bulk_deleting_items_and_empty_category_preserves_package_mapping_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $items = McuItem::factory()->count(2)->create(['mcu_category_id' => $category->id]);
        $unselected = McuItem::factory()->create();
        $schema = [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => $items->map(fn (McuItem $item): array => $item->definition())->all()]];
        $package = Paket::factory()->master()->create(['dynamic_schema' => $schema]);

        $this->post(route('mcu-item.bulk-destroy'), ['ids' => $items->modelKeys(), 'category' => $category->id])->assertRedirect(route('mcu-item.index', ['category' => $category->id]))->assertSessionHas('bulkDeleteResults', fn (array $results): bool => count($results) === 2 && $results[0]['deleted'] && $results[1]['deleted']);

        foreach ($items as $item) {
            $this->assertDatabaseMissing('mcu_items', ['id' => $item->id]);
        }
        $this->assertModelExists($unselected);
        $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$category->id]])->assertRedirect(route('mcu-category.index'));
        $this->assertDatabaseMissing('mcu_categories', ['id' => $category->id]);
        $this->assertSame($schema, $package->fresh()->dynamic_schema);
        $this->get(route('paket.show', $package))->assertOk()->assertSee($items[0]->label)->assertSee($items[1]->label);
    }

    public function test_bulk_selection_inputs_only_include_the_current_filtered_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Paket::factory()->master()->count(21)->create(['nama_paket' => 'Paket Cocok']);
        $otherPackage = Paket::factory()->master()->create(['nama_paket' => 'Tidak cocok']);
        McuCategory::factory()->count(31)->create(['name' => 'Kategori Cocok']);
        $otherCategory = McuCategory::factory()->create(['name' => 'Tidak cocok']);
        McuItem::factory()->count(31)->create(['mcu_category_id' => $otherCategory->id]);
        $otherItem = McuItem::factory()->create();
        McuSubcategory::factory()->count(31)->create(['mcu_category_id' => $otherCategory->id, 'name' => 'Subkategori Cocok']);
        $otherSubcategory = McuSubcategory::factory()->create(['name' => 'Tidak cocok']);

        foreach ([['paket.index', ['q' => 'Paket Cocok'], 20, $otherPackage->id], ['mcu-category.index', ['q' => 'Kategori Cocok'], 30, $otherCategory->id], ['mcu-item.index', ['category' => $otherCategory->id], 30, $otherItem->id], ['mcu-subcategory.index', ['category' => $otherCategory->id, 'q' => 'Subkategori Cocok'], 30, $otherSubcategory->id]] as [$route, $filters, $expected, $excluded]) {
            $response = $this->get(route($route, $filters))->assertOk()->assertSee('Pilih semua')->assertSee('Hapus terpilih')->assertSee('form="master-bulk-delete"', false)->assertDontSee('value="'.$excluded.'"', false);
            $this->assertSame($expected, substr_count($response->getContent(), 'data-bulk-delete-row'));
            $secondPage = $this->get(route($route, [...$filters, 'page' => 2]))->assertOk();
            $this->assertSame(1, substr_count($secondPage->getContent(), 'data-bulk-delete-row'));
        }
    }

    public function test_invalid_bulk_requests_do_not_delete_any_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->master()->create();
        $item = McuItem::factory()->create();
        foreach (['paket.bulk-destroy', 'mcu-category.bulk-destroy', 'mcu-item.bulk-destroy', 'mcu-subcategory.bulk-destroy'] as $route) {
            foreach ([[], ['ids' => []], ['ids' => ['not-uuid']], ['ids' => [$package->id, $package->id]], ['ids' => array_fill(0, 31, $package->id)], ['ids' => ['key' => $package->id]]] as $payload) {
                $this->postJson(route($route), $payload)->assertUnprocessable();
            }
        }
        $this->assertNotSoftDeleted($package);
        $this->assertModelExists($item);
        $this->assertModelExists($item->category);
    }

    public function test_guests_and_non_admin_users_cannot_bulk_delete(): void
    {
        $package = Paket::factory()->master()->create();
        $item = McuItem::factory()->create();
        foreach (['paket.bulk-destroy', 'mcu-category.bulk-destroy', 'mcu-item.bulk-destroy', 'mcu-subcategory.bulk-destroy'] as $route) {
            $this->post(route($route), ['ids' => [$package->id]])->assertRedirect(route('login'));
        }
        foreach (['petugas', 'dokter'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['paket.bulk-destroy', 'mcu-category.bulk-destroy', 'mcu-item.bulk-destroy', 'mcu-subcategory.bulk-destroy'] as $route) {
                $this->post(route($route), ['ids' => [$package->id]])->assertForbidden();
            }
        }
        $this->assertNotSoftDeleted($package);
        $this->assertModelExists($item);
        $this->assertModelExists($item->category);
    }

    public function test_result_names_are_escaped_and_repeated_deletion_is_reported_as_skipped(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create(['name' => '<script>Test</script>']);
        $payload = ['ids' => [$category->id]];
        $this->post(route('mcu-category.bulk-destroy'), $payload)->assertRedirect(route('mcu-category.index'));
        $this->get(route('mcu-category.index'))->assertOk()->assertDontSee('<script>Test</script>', false)->assertSee('&lt;script&gt;Test&lt;/script&gt;', false);
        $this->post(route('mcu-category.bulk-destroy'), $payload)->assertSessionHas('bulkDeleteResults', fn (array $results): bool => ! $results[0]['deleted']);
        $this->assertDatabaseMissing('mcu_categories', ['id' => $category->id]);
    }

    public function test_failed_and_vetoed_deletions_do_not_cancel_successful_rows(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $deleted = McuCategory::factory()->create(['name' => 'Berhasil']);
        $blocked = McuCategory::factory()->create(['name' => 'Ditolak']);
        $failed = McuCategory::factory()->create(['name' => 'Gagal']);
        Event::listen('eloquent.deleting: '.McuCategory::class, function (McuCategory $category): ?bool {
            if ($category->name === 'Ditolak') {
                return false;
            }
            if ($category->name === 'Gagal') {
                throw new \RuntimeException('Simulated deletion failure');
            }

            return null;
        });

        try {
            $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$deleted->id, $blocked->id, $failed->id]])->assertRedirect(route('mcu-category.index'))->assertSessionHas('bulkDeleteResults', fn (array $results): bool => $results[0]['deleted'] && ! $results[1]['deleted'] && ! $results[2]['deleted']);
        } finally {
            Event::forget('eloquent.deleting: '.McuCategory::class);
        }

        $this->assertDatabaseMissing('mcu_categories', ['id' => $deleted->id]);
        $this->assertModelExists($blocked);
        $this->assertModelExists($failed);
    }
}
