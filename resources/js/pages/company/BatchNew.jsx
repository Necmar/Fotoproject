import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, FullPageSpinner } from '../../components/ui';
import { useTranslation } from 'react-i18next';

/** /batches/new: creates a draft with the company defaults, then opens it. */
export default function BatchNew() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [error, setError] = useState(null);
    const started = useRef(false);

    useEffect(() => {
        if (started.current) return; // StrictMode runs effects twice in development
        started.current = true;

        api.post('/company/batches')
            .then(({ data }) => navigate(`/batches/${data.data.id}/edit`, { replace: true }))
            .catch((err) => setError(errorMessage(err)));
    }, [navigate]);

    if (!error) return <FullPageSpinner />;

    return (
        <div className="mx-auto max-w-md space-y-4">
            <Alert type="error">{error}</Alert>
            <Button to="/" variant="secondary">
                {t('errors.back_home')}
            </Button>
        </div>
    );
}
