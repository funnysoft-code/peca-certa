declare namespace App {
    namespace Data {
        export type OePart = {
            readonly oeNumber: string;
            readonly description: string;
            readonly brand: string;
        };
        export type PartRequestUnderstanding = {
            readonly category: string;
            readonly keywords: string[];
            readonly clarifyingQuestion: string | null;
            readonly confidence: number;
        };
        export type PartSearchResult = {
            readonly query: string;
            readonly variants: App.Data.PartVariant[];
            readonly searchUrl: string | null;
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
        };
    }
    namespace Enums {
        export type Supplier = 'autodelta' | 'autozitania';
    }
}
