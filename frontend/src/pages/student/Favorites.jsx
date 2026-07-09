import * as favoritesApi from '../../api/favorites.js';
import BookCard from '../../components/BookCard.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function Favorites() {
  const state = useAsync(favoritesApi.listFavorites, []);
  return (
    <>
      <PageHeader title="Favorites" description="Books saved for quick access." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <div className="book-grid">{state.data?.map((book) => <BookCard key={book.id} book={book} role="student" />)}</div>
      </DataState>
    </>
  );
}
