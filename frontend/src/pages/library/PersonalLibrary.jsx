import PageHeader from '../../components/PageHeader.jsx';
import PersonalLibraryPanel from '../../components/PersonalLibraryPanel.jsx';

export default function PersonalLibrary() {
  return (
    <>
      <PageHeader title="My saved reading files" description="Keep personal reading files for English narration. To publish a book into the searchable catalog, use Upload Book from the student menu." />
      <PersonalLibraryPanel />
    </>
  );
}
