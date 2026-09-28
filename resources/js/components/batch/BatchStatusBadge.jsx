import { useTranslation } from 'react-i18next';
import { Badge } from '../ui';

const TONES = {
    draft: 'grey',
    uploading: 'grey',
    queued: 'amber',
    processing: 'brand',
    completed: 'green',
    completed_with_errors: 'amber',
    failed: 'red',
};

export default function BatchStatusBadge({ status }) {
    const { t } = useTranslation();
    return <Badge tone={TONES[status] ?? 'grey'}>{t(`enums.batch_status.${status}`)}</Badge>;
}

export const ACTIVE_STATUSES = ['uploading', 'queued', 'processing'];
