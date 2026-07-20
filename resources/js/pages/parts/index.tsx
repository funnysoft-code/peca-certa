import { Head } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { SearchTab } from '@/components/parts/search-tab';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/parts';

let nextId = 1;

type Tab = { id: number };

export default function PartsIndex() {
    const [tabs, setTabs] = useState<Tab[]>([{ id: 0 }]);
    const [active, setActive] = useState(0);

    function addTab() {
        const id = nextId++;
        setTabs((t) => [...t, { id }]);
        setActive(id);
    }

    function closeTab(id: number) {
        setTabs((t) => {
            const next = t.filter((x) => x.id !== id);
            if (active === id && next.length > 0) {
                setActive(next[next.length - 1].id);
            }
            return next.length > 0 ? next : [{ id: 0 }];
        });
    }

    return (
        <>
            <Head title="Pesquisa de peças" />
            <div className="p-6">
                <div className="mb-4 flex items-center gap-1 border-b">
                    {tabs.map((tab, i) => (
                        <button
                            key={tab.id}
                            onClick={() => setActive(tab.id)}
                            className={`flex items-center gap-2 rounded-t-md px-4 py-2 text-sm transition-colors ${
                                active === tab.id
                                    ? 'bg-muted font-medium'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            Pesquisa {i + 1}
                            {tabs.length > 1 && (
                                <X
                                    className="size-3.5 opacity-60 hover:opacity-100"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        closeTab(tab.id);
                                    }}
                                />
                            )}
                        </button>
                    ))}
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={addTab}
                        className="ml-1"
                        title="Nova pesquisa"
                    >
                        <Plus className="size-4" />
                    </Button>
                </div>

                {/* Each tab stays mounted so results persist while another searches. */}
                {tabs.map((tab) => (
                    <div key={tab.id} hidden={active !== tab.id}>
                        <SearchTab />
                    </div>
                ))}
            </div>
        </>
    );
}

PartsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Pesquisa de peças',
            href: index(),
        },
    ],
};
