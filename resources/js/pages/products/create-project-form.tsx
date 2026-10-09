import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { type FashionModel } from '@/types/models';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function CreateProjectForm({ productId, fashionModels }: { productId: number; fashionModels: FashionModel[] }) {
    const { data, setData, post, processing, errors } = useForm({
        product_id: productId,
        fashion_model_id: fashionModels[0]?.id ?? 0,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.store'));
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid grid-cols-3 gap-2">
                {fashionModels.map((model) => (
                    <button
                        key={model.id}
                        type="button"
                        onClick={() => setData('fashion_model_id', model.id)}
                        className={`overflow-hidden rounded-lg border-2 ${data.fashion_model_id === model.id ? 'border-primary' : 'border-transparent'}`}
                        title={model.name}
                    >
                        <img src={model.photo_url} alt={model.name} className="aspect-[3/4] w-full object-cover" />
                    </button>
                ))}
            </div>
            <InputError message={errors.fashion_model_id ?? errors.product_id} />
            <Button disabled={processing} className="w-full">
                Tạo dự án video
            </Button>
        </form>
    );
}
