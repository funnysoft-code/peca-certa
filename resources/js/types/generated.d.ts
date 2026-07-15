declare namespace App {
    namespace Data {
        export type PartSearchResult = {
            readonly query: string;
            readonly variants: App.Data.PartVariant[];
        };
        export type PartVariant = {
            readonly brandName: string;
            readonly articleNumber: string;
            readonly traderArticleNumber: string;
            readonly purchasePrice: number | null;
            readonly retailPrice: number | null;
            readonly currency: string;
            readonly availableQuantity: number;
            readonly inStock: boolean;
            readonly warehouse: string;
            readonly url: string | null;
        };
    }
    namespace Enums {
        export type Supplier = 'autodelta' | 'autozitania';
    }
}
