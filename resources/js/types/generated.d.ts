declare namespace App {
    namespace Data {
        export type OePart = {
            readonly oeNumber: string;
            readonly description: string;
            readonly brand: string;
        };
        export type PartRequestUnderstanding = {
            readonly category: string;
            readonly searchTerm: string;
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
        export type SearchRunData = {
            readonly id: string;
            readonly kind: App.Enums.SearchRunKind;
            readonly status: App.Enums.SearchRunStatus;
            readonly requestText: string | null;
            readonly vin: string | null;
            readonly reference: string | null;
            readonly understanding: App.Data.PartRequestUnderstanding | null;
            readonly oeParts: App.Data.OePart[];
            readonly lookups: App.Data.SupplierLookupData[];
            readonly createdAt: string;
        };
        export type SupplierLookupData = {
            readonly id: string;
            readonly supplier: App.Enums.Supplier;
            readonly query: string;
            readonly oeDescription: string | null;
            readonly status: App.Enums.SupplierLookupStatus;
            readonly result: App.Data.PartSearchResult | null;
        };
    }
    namespace Enums {
        export type SearchRunKind = 'identify' | 'parts';
        export type SearchRunStatus = 'pending' | 'running' | 'done' | 'failed';
        export type Supplier = 'autodelta' | 'autozitania';
        export type SupplierLookupStatus =
            | 'pending'
            | 'running'
            | 'done'
            | 'failed'
            | 'empty';
    }
}
