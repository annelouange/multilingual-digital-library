import * as recommendationsApi from '../../api/recommendations.js';
import * as academicApi from '../../api/academic.js';
import * as searchApi from '../../api/search.js';
import BookCard from '../../components/BookCard.jsx';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { useAsync } from '../../hooks/useAsync.js';
import { useState } from 'react';

export default function Recommendations() {
  const state = useAsync(recommendationsApi.listRecommendations, []);
  const categories = useAsync(() => academicApi.listAcademic('categories'), []);
  const { user } = useAuth();
  const [choice, setChoice] = useState({ q: '', category_id: '', language: '' });
  const [chosenParams, setChosenParams] = useState(null);
  const chosenBooks = useAsync(
    () => chosenParams ? searchApi.searchBooks(chosenParams) : Promise.resolve({ data: { results: [] } }),
    [chosenParams]
  );
  const selectedBooks = chosenBooks.data?.results || [];

  return (
    <>
      <PageHeader title="Recommendations" description="Choose what you want to learn, then explore books matched to your goals." />
      <Card title="Choose your learning focus" eyebrow="Personal recommendations">
        <form className="recommendation-picker" onSubmit={(event) => {
          event.preventDefault();
          setChosenParams({
            q: choice.q,
            category_id: choice.category_id,
            language: choice.language,
            sort: '',
          });
        }}>
          <input value={choice.q} onChange={(event) => setChoice({ ...choice, q: event.target.value })} placeholder="Topic, author, skill, or book title" />
          <select value={choice.category_id} onChange={(event) => setChoice({ ...choice, category_id: event.target.value })}>
            <option value="">Any collection</option>
            {(categories.data || []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
          <select value={choice.language} onChange={(event) => setChoice({ ...choice, language: event.target.value })}>
            <option value="">English or French</option>
            <option value="en">English</option>
            <option value="fr">French</option>
          </select>
          <Button type="submit">Find Books for Me</Button>
        </form>
      </Card>
      {chosenParams && (
        <DataState loading={chosenBooks.loading} error={chosenBooks.error} empty={!selectedBooks.length} onRetry={chosenBooks.reload}>
          <Card title="Books chosen from your interests" eyebrow="Your selection">
            <div className="book-grid">{selectedBooks.map((book) => <BookCard key={book.id} book={book} role={user.role} />)}</div>
          </Card>
        </DataState>
      )}
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card title="Recommended by your library activity" eyebrow="Smart suggestions">
          <div className="book-grid">{state.data?.map((book) => <BookCard key={book.id} book={book} role={user.role} />)}</div>
        </Card>
      </DataState>
    </>
  );
}
