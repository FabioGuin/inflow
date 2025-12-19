<?php

namespace InFlow\Tests\Unit\Loaders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InFlow\Loaders\EloquentLoader;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\ColumnMapping;
use InFlow\ValueObjects\ModelMapping;
use InFlow\ValueObjects\Row;

class EloquentLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup in-memory SQLite database
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Create test table
        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->integer('age')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_loads_row_into_model(): void
    {
        $row = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => '30',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('age', 'age', ['cast:int']),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $model = $loader->load($row, $mapping);

        $this->assertInstanceOf(TestUser::class, $model);
        $this->assertEquals('John Doe', $model->name);
        $this->assertEquals('john@example.com', $model->email);
        $this->assertEquals(30, $model->age);
        $this->assertTrue($model->exists);
    }

    public function test_it_applies_transforms(): void
    {
        $row = new Row([
            'name' => '  JOHN DOE  ',
            'email' => '  JOHN@EXAMPLE.COM  ',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $model = $loader->load($row, $mapping);

        $this->assertEquals('JOHN DOE', $model->name);
        $this->assertEquals('john@example.com', $model->email);
    }

    public function test_it_applies_default_values(): void
    {
        $row = new Row([
            'name' => 'John Doe',
            'email' => '',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim'], default: 'unknown@example.com'),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $model = $loader->load($row, $mapping);

        $this->assertEquals('unknown@example.com', $model->email);
    }

    public function test_it_handles_nested_relations(): void
    {
        // Create addresses table
        Schema::create('test_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->string('street');
            $table->string('city');
            $table->timestamps();
        });

        $row = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'street' => '123 Main St',
            'city' => 'Milan',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('street', 'address.street', ['trim']),
                new ColumnMapping('city', 'address.city', ['trim']),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $model = $loader->load($row, $mapping);

        $model->load('address');

        $this->assertTrue($model->relationLoaded('address'));
        $this->assertEquals('123 Main St', $model->address->street);
        $this->assertEquals('Milan', $model->address->city);
    }

    public function test_it_handles_has_one_lookup_existing_address(): void
    {
        // Create addresses table
        Schema::create('test_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->string('street');
            $table->string('city');
            $table->timestamps();
        });

        // Existing user + address (the one we want to "lookup" and reattach)
        $existingUser = TestUser::create([
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'age' => 40,
        ]);

        $existingAddress = TestAddress::create([
            'user_id' => $existingUser->id,
            'street' => '123 Main St',
            'city' => 'Old City',
        ]);

        $row = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'street' => '123 Main St',
            'city' => 'Milan',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('street', 'address.street', ['trim'], relationLookup: [
                    'field' => 'street',
                    'create_if_missing' => false,
                ]),
                new ColumnMapping('city', 'address.city', ['trim']),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $user = $loader->load($row, $mapping);

        $this->assertInstanceOf(TestUser::class, $user);

        $existingAddress->refresh();

        // Lookup should re-attach the address to the newly created user and update fields
        $this->assertEquals($user->id, $existingAddress->user_id);
        $this->assertEquals('Milan', $existingAddress->city);
        $this->assertEquals('123 Main St', $existingAddress->street);
    }

    public function test_it_does_not_create_empty_optional_has_one_relation(): void
    {
        Schema::create('test_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->timestamps();
        });

        $row = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('street', 'address.?street', ['trim']),
                new ColumnMapping('city', 'address.?city', ['trim']),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $user = $loader->load($row, $mapping);

        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertEquals(0, TestAddress::count());
    }

    public function test_it_handles_belongs_to_lookup_by_name(): void
    {
        // Create categories table
        Schema::create('test_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Update products table to include category_id
        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->foreignId('category_id')->nullable()->constrained('test_categories')->onDelete('set null');
            $table->timestamps();
        });

        $row = new Row([
            'name' => 'Laptop',
            'price' => '999.99',
            'category_name' => 'Electronics',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestProduct::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('price', 'price', ['cast:float']),
                new ColumnMapping('category_name', 'category.name', ['trim'], relationLookup: [
                    'field' => 'name',
                    'create_if_missing' => true,
                ]),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $product = $loader->load($row, $mapping);

        $this->assertInstanceOf(TestProduct::class, $product);
        $this->assertEquals('Laptop', $product->name);
        $this->assertEquals(999.99, $product->price);
        $this->assertNotNull($product->category_id);
        $this->assertTrue($product->relationLoaded('category'));
        $this->assertEquals('Electronics', $product->category->name);
    }

    public function test_it_handles_belongs_to_lookup_existing_category(): void
    {
        // Create categories table
        Schema::create('test_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Create existing category
        $existingCategory = TestCategory::create(['name' => 'Electronics']);

        // Update products table
        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->foreignId('category_id')->nullable()->constrained('test_categories')->onDelete('set null');
            $table->timestamps();
        });

        $row = new Row([
            'name' => 'Laptop',
            'price' => '999.99',
            'category_name' => 'Electronics',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestProduct::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('price', 'price', ['cast:float']),
                new ColumnMapping('category_name', 'category.name', ['trim'], relationLookup: [
                    'field' => 'name',
                    'create_if_missing' => false, // Don't create if missing
                ]),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $product = $loader->load($row, $mapping);

        $this->assertEquals($existingCategory->id, $product->category_id);
        $this->assertEquals('Electronics', $product->category->name);
    }

    public function test_it_handles_belongs_to_lookup_missing_category_without_create(): void
    {
        // Create categories table
        Schema::create('test_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Update products table
        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->foreignId('category_id')->nullable()->constrained('test_categories')->onDelete('set null');
            $table->timestamps();
        });

        $row = new Row([
            'name' => 'Laptop',
            'price' => '999.99',
            'category_name' => 'NonExistentCategory',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestProduct::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('price', 'price', ['cast:float']),
                new ColumnMapping('category_name', 'category.name', ['trim'], relationLookup: [
                    'field' => 'name',
                    'create_if_missing' => false, // Don't create if missing
                ]),
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $product = $loader->load($row, $mapping);

        $this->assertEquals('Laptop', $product->name);
        $this->assertNull($product->category_id); // Category not found and not created
    }

    public function test_it_handles_has_many_lookup_update_and_create_in_batch(): void
    {
        Schema::create('test_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->string('order_number');
            $table->string('status')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'order_number']);
        });

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('orders', 'orders.order_number', [], relationLookup: [
                    'field' => 'order_number',
                    'create_if_missing' => true,
                ]),
                new ColumnMapping('orders', 'orders.status', ['trim']),
            ],
            options: [
                'unique_key' => 'email',
                'duplicate_strategy' => 'update',
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);

        // First run: create 2 orders
        $row1 = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'orders' => [
                ['order_number' => 'A1', 'status' => 'new'],
                ['order_number' => 'B2', 'status' => 'new'],
            ],
        ], 1);

        $user1 = $loader->load($row1, $mapping);
        $this->assertInstanceOf(TestUser::class, $user1);
        $this->assertCount(2, $user1->orders()->get());

        // Second run: update A1 + create C3
        $row2 = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'orders' => [
                ['order_number' => 'A1', 'status' => 'paid'],
                ['order_number' => 'C3', 'status' => 'new'],
            ],
        ], 2);

        $user2 = $loader->load($row2, $mapping);
        $this->assertInstanceOf(TestUser::class, $user2);

        $orders = $user2->orders()->orderBy('order_number')->get();
        $this->assertCount(3, $orders);

        $this->assertEquals('paid', $user2->orders()->where('order_number', 'A1')->value('status'));
        $this->assertEquals('new', $user2->orders()->where('order_number', 'C3')->value('status'));
    }

    public function test_it_does_not_create_empty_optional_has_many_relation(): void
    {
        Schema::create('test_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->string('order_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        $row = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ], 1);

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('order_number', 'orders.?order_number', ['trim']),
                new ColumnMapping('status', 'orders.?status', ['trim']),
            ],
            options: [
                'unique_key' => 'email',
                'duplicate_strategy' => 'update',
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);
        $user = $loader->load($row, $mapping);

        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertEquals(0, TestOrder::count());
    }

    public function test_it_handles_belongs_to_many_with_pivot_and_sync_strategy(): void
    {
        Schema::create('test_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('test_tag_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('test_tags')->onDelete('cascade');
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tag_id']);
        });

        $mapping = new ModelMapping(
            modelClass: TestUser::class,
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
                new ColumnMapping('tags', 'tags.name', [], relationLookup: [
                    'field' => 'name',
                    'create_if_missing' => true,
                ]),
                new ColumnMapping('tag_role', 'tags.pivot.role', ['trim']),
            ],
            options: [
                'unique_key' => 'email',
                'duplicate_strategy' => 'update',
                'belongs_to_many_strategy' => 'sync',
            ]
        );

        $loader = $this->app->make(EloquentLoader::class);

        $row1 = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'tags' => [
                ['name' => 'VIP'],
                ['name' => 'New'],
            ],
            'tag_role' => 'primary',
        ], 1);

        $user1 = $loader->load($row1, $mapping);
        $this->assertInstanceOf(TestUser::class, $user1);
        $this->assertCount(2, $user1->tags()->get());

        $vipTag = TestTag::where('name', 'VIP')->first();
        $this->assertNotNull($vipTag);
        $this->assertEquals('primary', $user1->tags()->where('test_tags.id', $vipTag->id)->first()->pivot->role);

        // Second run: keep only VIP, change pivot role -> should detach "New" and update pivot
        $row2 = new Row([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'tags' => [
                ['name' => 'VIP'],
            ],
            'tag_role' => 'secondary',
        ], 2);

        $user2 = $loader->load($row2, $mapping);
        $this->assertInstanceOf(TestUser::class, $user2);
        $this->assertCount(1, $user2->tags()->get());
        $this->assertEquals('secondary', $user2->tags()->where('test_tags.id', $vipTag->id)->first()->pivot->role);
    }
}

/**
 * Test model for EloquentLoader tests
 */
class TestUser extends Model
{
    protected $table = 'test_users';

    protected $fillable = ['name', 'email', 'age'];

    public function address()
    {
        return $this->hasOne(TestAddress::class, 'user_id');
    }

    public function orders()
    {
        return $this->hasMany(TestOrder::class, 'user_id');
    }

    public function tags()
    {
        return $this->belongsToMany(TestTag::class, 'test_tag_user', 'user_id', 'tag_id')
            ->withPivot('role');
    }
}

class TestAddress extends Model
{
    protected $table = 'test_addresses';

    protected $fillable = ['user_id', 'street', 'city'];
}

class TestProduct extends Model
{
    protected $table = 'test_products';

    protected $fillable = ['name', 'price', 'category_id'];

    public function category()
    {
        return $this->belongsTo(TestCategory::class, 'category_id');
    }
}

class TestCategory extends Model
{
    protected $table = 'test_categories';

    protected $fillable = ['name'];
}

class TestOrder extends Model
{
    protected $table = 'test_orders';

    protected $fillable = ['user_id', 'order_number', 'status'];
}

class TestTag extends Model
{
    protected $table = 'test_tags';

    protected $fillable = ['name'];
}
