import { Headphones, Languages, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import * as borrowApi from '../../api/borrow.js';
import * as booksApi from '../../api/books.js';
import * as favoritesApi from '../../api/favorites.js';
import * as translationsApi from '../../api/translations.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { useAsync } from '../../hooks/useAsync.js';
import { ACCESS_LANGUAGES } from '../../utils/multilingualSpeech.js';

export default function BookDetails() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [translationLanguage, setTranslationLanguage] = useState('rw');
  const [translationResult, setTranslationResult] = useState(null);
  const [translationBusy, setTranslationBusy] = useState(false);
  const bookState = useAsync(() => booksApi.getBook(id), [id]);
  const book = bookState.data;

  async function runAction(action, successMessage) {
    setError('');
    setMessage('');
    try {
      await action();
      setMessage(successMessage);
    } catch (err) {
      setError(err.message);
    }
  }


  async function translateBook() {
    setError('');
    setMessage('');
    setTranslationBusy(true);
    try {
      const response = await translationsApi.translateBook(book.id, { source_language: 'en', target_language: translationLanguage });
      setTranslationResult(response.data);
      setMessage(`Book translated to ${translationLanguage.toUpperCase()} with ${response.data?.provider || 'AI'}. Review is recommended before publishing.`);
    } catch (err) {
      setError(err.message);
    } finally {
      setTranslationBusy(false);
    }
  }
  async function deleteBook() {
    if (!window.confirm(`Delete "${book.title}" from the public catalogue? Existing activity history will be preserved.`)) return;
    setError('');
    try {
      await booksApi.deleteBook(book.id);
      navigate('/admin/books', { replace: true, state: { message: `"${book.title}" was deleted.` } });
    } catch (err) {
      setError(err.message);
    }
  }

  return (
    <DataState loading={bookState.loading} error={bookState.error} empty={!book} onRetry={bookState.reload}>
      <PageHeader title={book?.title} description={book?.description} />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}
      <section className="details-layout">
        <div className="book-cover hero-cover" style={{ background: book?.coverColor }}>
          {book?.coverImage ? <img src={booksApi.assetUrl(book.coverImage)} alt={`${book.title} cover`} /> : <span>{book?.title?.slice(0, 2).toUpperCase()}</span>}
        </div>
        <Card title="Book information">
          <div className="detail-grid">
            <span>Author</span><strong>{book?.author}</strong>
            <span>ISBN</span><strong>{book?.isbn}</strong>
            <span>Year</span><strong>{book?.year}</strong>
            <span>Faculty</span><strong>{book?.faculty}</strong>
            <span>Department</span><strong>{book?.department}</strong>
            <span>Course</span><strong>{book?.course}</strong>
            <span>Availability</span><strong>{book?.availableCopies} / {book?.totalCopies}</strong>
          </div>
          <div className="button-row">
            {user.role === 'student' && <Button onClick={() => runAction(() => borrowApi.requestBorrow(book.id), 'Borrow request sent to the librarian.')}>Request digital borrow</Button>}
            {user.role === 'student' && <Button variant="secondary" onClick={() => runAction(() => favoritesApi.toggleFavorite(book.id), 'Favorites updated.')}>Favorite</Button>}
            <Link className="button button-ghost button-md" to={`/reader/${book?.id}`}><Headphones size={16} /> Read & listen</Link>
            <label className="inline-select-action"><Languages size={15} />
              <select value={translationLanguage} onChange={(event) => setTranslationLanguage(event.target.value)} aria-label="Whole book translation language">
                {ACCESS_LANGUAGES.filter((item) => item.code !== 'en').map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}
              </select>
            </label>
            <Button variant="secondary" onClick={translateBook} disabled={translationBusy}><Languages size={16} /> {translationBusy ? 'Translating...' : 'Translate book'}</Button>
            {user.role === 'librarian_admin' && <Button variant="danger" onClick={deleteBook}><Trash2 size={16} /> Delete book</Button>}
          </div>
        </Card>
        <Card title="Borrowing guidance" eyebrow="Digital access">
          <p>Requesting a borrow sends the book to a librarian for approval. When approved, it appears in your borrowed books with a due date; you can read online, listen with English narration, renew if allowed, or return it when finished.</p>
        </Card>
        {translationResult && (
          <Card title="Translation preview" eyebrow={`${translationResult.provider || 'AI'} / ${translationResult.model || 'model'}`}>
            <div className="transcript-panel">
              <span>{translationResult.source_language} to {translationResult.target_language}</span>
              <strong>{translationResult.translated_text}</strong>
              <small>Status: {translationResult.quality_status}</small>
              {translationResult.source_truncated && <small>Large book preview was truncated for synchronous processing.</small>}
            </div>
          </Card>
        )}
        <Card title="Book files">
          {book?.files?.length ? (
            <div className="file-list">
              {book.files.map((file) => (
                <div className="file-row" key={file.id}>
                  <span>{file.original_name}</span>
                  <small>{file.file_type}</small>
                </div>
              ))}
            </div>
          ) : (
            <p>No files uploaded for this book yet.</p>
          )}
        </Card>
      </section>
    </DataState>
  );
}
