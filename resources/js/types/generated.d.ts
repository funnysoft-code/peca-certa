declare namespace App {
namespace Data {
export type AgentStep = {
readonly id: string,
readonly tool: string,
readonly label: string,
readonly status: string,
readonly detail: string | null,
readonly at: string,
};
export type FindingData = {
readonly id: string,
readonly supplier: App.Enums.Supplier,
readonly brand: string,
readonly article: string,
readonly traderArticleNumber: string,
readonly price: number | null,
readonly currency: string,
readonly availableQuantity: number,
readonly inStock: boolean,
readonly warehouse: string,
};
export type IdentifyClarification = {
readonly question: string,
readonly options: string[],
readonly kind: string | null,
};
export type OePart = {
readonly oeNumber: string,
readonly description: string,
readonly brand: string,
};
export type PaginatedFindingsData = {
readonly data: App.Data.FindingData[],
readonly links: unknown,
readonly meta: unknown,
};
export type PaginatedSearchRunsData = {
readonly data: App.Data.SearchRunData[],
readonly links: unknown,
readonly meta: unknown,
};
export type PartRequestUnderstanding = {
readonly category: string,
readonly searchTerm: string,
readonly keywords: string[],
readonly clarifyingQuestion: string | null,
readonly confidence: number,
};
export type PartSearchResult = {
readonly query: string,
readonly variants: App.Data.PartVariant[],
readonly searchUrl: string | null,
};
export type PartVariant = {
readonly brandName: string,
readonly articleNumber: string,
readonly traderArticleNumber: string,
readonly purchasePrice: number | null,
readonly retailPrice: number | null,
readonly currency: string,
readonly availableQuantity: number,
readonly inStock: boolean,
readonly warehouse: string,
};
export type SearchRunData = {
readonly id: string,
readonly kind: App.Enums.SearchRunKind,
readonly status: App.Enums.SearchRunStatus,
readonly requestText: string | null,
readonly vin: string | null,
readonly reference: string | null,
readonly understanding: App.Data.PartRequestUnderstanding | null,
readonly pendingQuestion: App.Data.IdentifyClarification | null,
readonly oeParts: App.Data.OePart[],
readonly lookups: App.Data.SupplierLookupData[],
readonly agentSteps: App.Data.AgentStep[],
readonly createdAt: string,
readonly authorName: string,
readonly unavailableIncluded: boolean,
};
export type SupplierLookupData = {
readonly id: string,
readonly supplier: App.Enums.Supplier,
readonly query: string,
readonly oeDescription: string | null,
readonly status: App.Enums.SupplierLookupStatus,
readonly result: App.Data.PartSearchResult | null,
};
}
namespace Enums {
export type ReasoningEffort = 'none' | 'low' | 'medium' | 'high';
export type SearchRunKind = 'identify' | 'parts';
export type SearchRunStatus = 'pending' | 'running' | 'needs_input' | 'done' | 'failed' | 'cancelled';
export type Supplier = 'autodelta' | 'autozitania';
export type SupplierLookupStatus = 'pending' | 'running' | 'done' | 'failed' | 'empty';
export type XaiModel = 'grok-4.3' | 'grok-4.5';
}
}
