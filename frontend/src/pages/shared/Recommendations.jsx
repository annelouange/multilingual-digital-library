import * as recommendationsApi from '../../api/recommendations.js';
import BookCard from '../../components/BookCard.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function Recommendations() {
  const state = useAsync(recommendationsApi.listRecommendations, []);
  const { user } = useAuth();
  return (
    <>
      <PageHeader title="Recommendations" description="Suggested resources based on faculty, department, and course activity." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <div className="book-grid">{state.data?.map((book) => <BookCard key={book.id} book={book} role={user.role} />)}</div>
      </DataState>
    </>
  );
}
