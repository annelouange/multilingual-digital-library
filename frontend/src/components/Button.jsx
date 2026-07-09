export default function Button({ children, type = 'button', variant = 'primary', size = 'md', className = '', ...props }) {
  return (
    <button type={type} className={`button button-${variant} button-${size} ${className}`} {...props}>
      {children}
    </button>
  );
}
