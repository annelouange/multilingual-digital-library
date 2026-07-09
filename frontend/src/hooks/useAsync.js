import { useCallback, useEffect, useState } from 'react';

export function useAsync(loader, dependencies = []) {
  const [state, setState] = useState({ loading: true, error: '', data: null, mock: false });

  const run = useCallback(async () => {
    setState((current) => ({ ...current, loading: true, error: '' }));
    try {
      const response = await loader();
      setState({
        loading: false,
        error: '',
        data: response.data ?? response,
        mock: Boolean(response.mock),
      });
    } catch (error) {
      setState({ loading: false, error: error.message, data: null, mock: false });
    }
  }, dependencies);

  useEffect(() => {
    run();
  }, [run]);

  return { ...state, reload: run };
}
