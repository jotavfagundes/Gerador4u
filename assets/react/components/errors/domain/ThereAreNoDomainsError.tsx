const ThereAreNoDomainsError = () => {
  return (
    <section className="hero is-dark">
      <div className="hero-body">
        <div className="container">
          <div className="columns is-centered">
            <div className="column is-8">
              <div className="notification is-warning has-text-centered">
                Nenhum domínio cadastrado. Adicione um na <a target="_blank" href="/admin">área administrativa</a>.
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

export default ThereAreNoDomainsError;
