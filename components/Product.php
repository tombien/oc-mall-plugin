<?php namespace OFFLINE\Mall\Components;

use Auth;
use Cms\Classes\ComponentBase;
use Hashids\Hashids;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use OFFLINE\Mall\Classes\Traits\SetVars;
use OFFLINE\Mall\Models\Cart;
use OFFLINE\Mall\Models\Product as ProductModel;
use OFFLINE\Mall\Models\Property;
use OFFLINE\Mall\Models\PropertyValue;
use OFFLINE\Mall\Models\Variant;
use Request;

class Product extends ComponentBase
{
    use SetVars;

    /**
     * @var Product|Variant;
     */
    public $item;
    /**
     * @var Product;
     */
    public $product;
    /**
     * @var Collection
     */
    public $variants;
    /**
     * Available product properties. Named "props" to prevent
     * naming conflict with base class.
     *
     * @var Collection
     */
    public $props;
    /**
     * @var Variant
     */
    public $variant;
    /**
     * @var integer
     */
    public $variantId;

    public function componentDetails()
    {
        return [
            'name'        => 'offline.mall::lang.components.product.details.name',
            'description' => 'offline.mall::lang.components.product.details.description',
        ];
    }

    public function defineProperties()
    {
        return [
            'product' => [
                'title'   => 'offline.mall::lang.common.product',
                'default' => ':slug',
                'type'    => 'dropdown',
            ],
            'variant' => [
                'title'   => 'offline.mall::lang.common.variant',
                'default' => ':slug',
                'depends' => ['product'],
                'type'    => 'dropdown',
            ],
        ];
    }

    public function getProductOptions()
    {
        return [':slug' => trans('offline.mall::lang.components.category.properties.use_url')]
            + ProductModel::get()->pluck('name', 'id')->toArray();
    }

    public function getVariantOptions()
    {
        $product = Request::input('product');
        if ( ! $product || $product === ':slug') {
            return [':slug' => trans('offline.mall::lang.components.category.properties.use_url')];
        }

        return [':slug' => trans('offline.mall::lang.components.category.properties.use_url')]
            + ProductModel::find($product)->variants->pluck('name', 'id')->toArray();
    }

    public function onRun()
    {
        $this->setData();

        // If this product is managed by it's variants we redirect to the first available variant.
        if ($this->product->inventory_management_method !== 'single' && ! $this->param('variant')) {

            $variant = $this->product->variants->first();
            if ( ! $variant) {
                $this->controller->run('404');
            }

            $url = $this->controller->pageUrl($this->page->fileName, [
                'slug'    => $this->product->slug,
                'variant' => $variant->hashId,
            ]);

            return Redirect::to($url);
        }
    }

    public function onAddToCart()
    {
        $ids = collect(post('props'))->map(function ($id) {
            return $this->decode($id);
        });

        $variant = Variant::whereHas('property_values', function ($q) use ($ids) {
            $q->whereIn('id', $ids);
        })->first();

        $cart = Cart::byUser(Auth::getUser());
        $cart->addProduct($this->getProduct(), $variant);
    }

    public function setData()
    {
        $variantId = $this->decode($this->param('variant'));

        $this->setVar('variantId', $variantId ? $variantId[0] : null);
        $this->setVar('item', $this->getItem());
        $this->setVar('variants', $this->getVariants());
        $this->setVar('props', $this->getProps());
    }

    protected function getItem()
    {
        $this->product = $this->getProduct();
        $variant       = $this->property('variant');

        // No Variant was requested via URL
        if ( ! $this->param('variant')) {
            return $this->product;
        }

        $model = Variant::published()->with(['property_values', 'images', 'main_image']);

        if ($variant === ':slug') {
            return $this->variant = $model->where('product_id', $this->product->id)
                                          ->findOrFail($this->variantId);
        }

        return $this->variant = $model->where('product_id', $this->product->id)->findOrFail($variant);
    }

    public function getProduct(): ProductModel
    {
        $product = $this->property('product');
        $model   = ProductModel::published()->with([
            'variants',
            'variants.property_values',
            'variants.images',
            'variants.main_image',
            'images',
            'downloads',
        ]);

        if ($product === ':slug') {
            return $model->where('slug', $this->param('slug'))->firstOrFail();
        }

        return $model->findOrFail($product);
    }

    protected function getVariants(): Collection
    {
        if ($this->product->inventory_management_method === 'single') {
            return collect();
        }

        $variants = $this->product->variants->reject(function (Variant $variant) {
            // Remove the currently active variant
            return $variant->id === $this->variantId;
        })->groupBy(function (Variant $variant) {
            return $this->getGroupedProperty($variant)->value;
        });

        if ($this->variant) {
            // Remove the property value of the currently viewed variant
            $variants->pull($this->getGroupedProperty($this->variant)->value);
        }

        return $variants;
    }

    protected function getGroupedProperty(Variant $variant)
    {
        return $variant->property_values->first(function (PropertyValue $value) use ($variant) {
            return $value->property_id === $variant->product->group_by_property_id;
        });
    }

    protected function getProps()
    {
        $groupedValue = $this->getGroupedProperty($this->variant)->value;
        if ( ! $groupedValue) {
            return collect([]);
        }

        $ids = PropertyValue::where('value', $groupedValue)
                            ->get(['describable_id'])
                            ->pluck('describable_id')
                            ->unique();

        $valueMap = PropertyValue::whereIn('describable_id', $ids)
                                 ->where('describable_type', Variant::class)
                                 ->where('value', '<>', '')
                                 ->whereNotNull('value')
                                 ->get()
                                 ->groupBy('property_id');

        return $this->product->category->properties->reject(function (Property $property) {
            return $property->id === $this->product->group_by_property_id;
        })->map(function (Property $property) use ($valueMap) {
            return (object)[
                'property' => $property,
                'values'   => $valueMap->get($property->id),
            ];
        })->filter(function ($collection) {
            return $collection->values && $collection->values->count() > 0;
        })->values();
    }

    /**
     * @param $id
     *
     * @return mixed
     */
    protected function decode($id)
    {
        return app(Hashids::class)->decode($id);
    }
}